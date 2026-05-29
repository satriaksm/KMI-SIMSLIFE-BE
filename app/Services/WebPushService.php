<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    private function makeWebPush(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ]);
    }

    public function sendMerchantApplicationDecision(User $user, Merchant $merchant, string $status = 'approved'): void
    {
        $payload = json_encode($this->buildPayload($merchant, $status), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->sendPayloadToUser($user, $payload, 'Merchant decision', 'merchant');
    }

    /**
     * Setelah checkout: Transfer → ingatkan bayar (pembeli).
     * COD → beri tahu UMKM ada pesanan baru.
     */
    public function notifyOrderCreated(Order $order): void
    {
        $order = $this->loadOrderRelations($order);

        if ($this->isCod($order)) {
            $this->notifyMerchantNewOrder($order);
            return;
        }

        $code = $order->order_code ?: ('#' . $order->id);
        $this->notifyCustomer(
            $order,
            'Bayar tagihan pesanan Anda',
            "Selesaikan pembayaran pesanan {$code} agar diproses penjual.",
            'order-pay-' . $order->id,
        );
    }

    /**
     * Setelah pembayaran transfer berhasil: hanya UMKM (pesanan baru).
     * Pembeli tidak di-spam notifikasi "paid".
     */
    public function sendPaymentStatusUpdate(Order $order, Payment $payment): void
    {
        $order = $this->loadOrderRelations($order);
        $paymentStatus = strtolower((string) $payment->status);

        if ($paymentStatus === 'paid') {
            if (!$this->isCod($order)) {
                $this->notifyMerchantNewOrder($order);
            }
            return;
        }

        $messages = [
            'expired' => ['Pembayaran kedaluwarsa', 'Tagihan pesanan Anda telah kedaluwarsa.'],
            'failed' => ['Pembayaran gagal', 'Pembayaran pesanan gagal diproses.'],
        ];

        if (!isset($messages[$paymentStatus])) {
            return;
        }

        [$title, $body] = $messages[$paymentStatus];
        $this->notifyCustomer($order, $title, $body, 'payment-status-' . $payment->id);
    }

    /**
     * Perubahan status pesanan (responsed, delivered, completed, cancelled).
     * Status paid/pending tidak memicu push (hindari duplikat & "paid" langsung).
     */
    /**
     * @param  'merchant_reject'|'customer_cancel'|'auto'|null  $cancelContext
     */
    public function sendOrderStatusUpdate(Order $order, ?string $cancelContext = null): void
    {
        $order = $this->loadOrderRelations($order);
        $status = (string) $order->status;

        if (in_array($status, ['pending', 'paid'], true)) {
            return;
        }

        match ($status) {
            'responsed' => $this->notifyOrderAccepted($order),
            'delivered' => $this->notifyOrderDelivered($order),
            'completed' => $this->notifyOrderCompleted($order),
            'cancelled' => $this->notifyOrderCancelled($order, $cancelContext),
            default => null,
        };
    }

    private function notifyOrderAccepted(Order $order): void
    {
        if ($this->isCod($order) && $this->isPickup($order)) {
            $this->notifyCustomer(
                $order,
                'Pesanan siap diambil',
                'Silakan ambil pesanan Anda di toko.',
                'order-responsed-' . $order->id,
            );
            return;
        }

        $this->notifyCustomer(
            $order,
            'Pesanan diterima',
            'Pesanan Anda diterima dan sedang diproses penjual.',
            'order-responsed-' . $order->id,
        );
    }

    private function notifyOrderDelivered(Order $order): void
    {
        if ($this->isPickup($order)) {
            return;
        }

        $this->notifyCustomer(
            $order,
            'Pesanan diantar',
            'Pesanan Anda sedang dalam pengiriman.',
            'order-delivered-' . $order->id,
        );
    }

    private function notifyOrderCompleted(Order $order): void
    {
        if ($this->isCod($order)) {
            $this->notifyCustomer(
                $order,
                'Pesanan selesai',
                'Pesanan telah diambil dan dibayar.',
                'order-completed-' . $order->id,
            );
            $this->notifyMerchant(
                $order,
                'Pesanan selesai',
                'Pesanan COD telah diambil dan dibayar.',
                'order-completed-' . $order->id,
            );
            return;
        }

        $this->notifyCustomer(
            $order,
            'Pesanan selesai',
            'Pesanan telah diterima.',
            'order-completed-' . $order->id,
        );
        $this->notifyMerchant(
            $order,
            'Pesanan selesai',
            'Pesanan telah diterima.',
            'order-completed-' . $order->id,
        );
    }

    private function notifyOrderCancelled(Order $order, ?string $cancelContext = null): void
    {
        $body = match ($cancelContext) {
            'merchant_reject' => 'Pesanan Anda ditolak.',
            'customer_cancel' => 'Pesanan Anda dibatalkan.',
            'auto' => 'Pesanan Anda dibatalkan karena batas waktu habis.',
            default => (!$order->responsed_at && $order->paid_at)
                ? 'Pesanan Anda ditolak.'
                : 'Pesanan Anda dibatalkan.',
        };

        $this->notifyCustomer(
            $order,
            'Pesanan dibatalkan',
            $body,
            'order-cancelled-' . $order->id,
        );
    }

    private function notifyMerchantNewOrder(Order $order): void
    {
        $productLabel = $this->orderProductLabel($order);
        $this->notifyMerchant(
            $order,
            'Pesanan baru',
            "Anda mendapatkan pesanan \"{$productLabel}\".",
            'order-new-' . $order->id,
        );
    }

    private function notifyCustomer(Order $order, string $title, string $body, string $tag): void
    {
        if (!$order->user) {
            return;
        }

        $payload = $this->buildOrderPayload($order, $title, $body, $tag, 'customer');
        $this->sendPayloadToUser($order->user, $payload, 'Order customer', 'customer');
    }

    private function notifyMerchant(Order $order, string $title, string $body, string $tag): void
    {
        $merchantUser = $order->merchant?->user;
        if (!$merchantUser || $this->isBuyerAlsoMerchantOwner($order)) {
            return;
        }

        $payload = $this->buildOrderPayload($order, $title, $body, $tag, 'merchant');
        $this->sendPayloadToUser($merchantUser, $payload, 'Order merchant', 'merchant');
    }

    private function buildOrderPayload(Order $order, string $title, string $body, string $tag, string $role): string
    {
        $baseUrl = rtrim((string) config('app.frontend_url'), '/');

        $url = $role === 'merchant'
            ? $baseUrl . '/merchant-center/' . ($order->merchant?->slug ?? '') . '/orders/' . $order->id
            : $baseUrl . '/orders/' . $order->id;

        return json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/icon192.png',
            'badge' => '/icon192.png',
            'tag' => $tag,
            'data' => [
                'url' => $url,
                'order_id' => $order->id,
                'status' => $order->status,
                'role' => $role,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function loadOrderRelations(Order $order): Order
    {
        return $order->loadMissing(['user', 'merchant.user', 'items']);
    }

    private function isCod(Order $order): bool
    {
        return strtoupper((string) ($order->payment_method ?? '')) === 'COD';
    }

    private function isPickup(Order $order): bool
    {
        return (string) ($order->delivery_type ?? '') === 'pickup';
    }

    private function isBuyerAlsoMerchantOwner(Order $order): bool
    {
        $buyerId = $order->user_id ?? $order->user?->id;
        $merchantOwnerId = $order->merchant?->user_id ?? $order->merchant?->user?->id;

        if (!$buyerId || !$merchantOwnerId) {
            return false;
        }

        return (int) $buyerId === (int) $merchantOwnerId;
    }

    private function subscriptionMatchesAudience(PushSubscription $subscription, string $audience): bool
    {
        $audiences = $subscription->audiences;

        if (!is_array($audiences) || $audiences === []) {
            return false;
        }

        return in_array($audience, $audiences, true);
    }

    private function orderProductLabel(Order $order): string
    {
        $names = $order->items
            ->pluck('product_name_snapshot')
            ->filter(fn ($n) => is_string($n) && trim($n) !== '')
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            return 'produk';
        }

        if ($names->count() === 1) {
            return $names->first();
        }

        return $names->first() . ' +' . ($names->count() - 1) . ' lainnya';
    }

    private function buildPayload(Merchant $merchant, string $status): array
    {
        $approved = $status === 'approved';

        return [
            'title' => $approved ? 'UMKM disetujui' : 'UMKM ditolak',
            'body' => $approved
                ? 'Pendaftaran UMKM "' . $merchant->name . '" telah disetujui.'
                : 'Pendaftaran UMKM "' . $merchant->name . '" ditolak.',
            'icon' => '/icon192.png',
            'badge' => '/icon192.png',
            'tag' => 'merchant-application-' . $merchant->id,
            'data' => [
                'url' => rtrim((string) config('app.frontend_url'), '/') . '/dashboard',
                'merchant_id' => $merchant->id,
                'status' => $status,
            ],
        ];
    }

    private function toSubscription(PushSubscription $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => $subscription->endpoint,
            'keys' => [
                'p256dh' => $subscription->p256dh,
                'auth' => $subscription->auth,
            ],
            'contentEncoding' => $subscription->content_encoding,
        ]);
    }

    private function sendPayloadToUser(
        User $user,
        string $payload,
        string $context,
        ?string $audience = null,
    ): void {
        $subscriptions = $user->pushSubscriptions()->get();

        if ($audience !== null) {
            $subscriptions = $subscriptions->filter(
                fn (PushSubscription $sub) => $this->subscriptionMatchesAudience($sub, $audience),
            );
        }

        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = $this->makeWebPush();

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification($this->toSubscription($subscription), $payload);
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            Log::warning('[WebPush] ' . $context . ' push failed', [
                'endpoint' => $report->getEndpoint(),
                'reason' => $report->getReason(),
                'expired' => $report->isSubscriptionExpired(),
            ]);

            if ($report->isSubscriptionExpired()) {
                PushSubscription::query()->where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }
}
