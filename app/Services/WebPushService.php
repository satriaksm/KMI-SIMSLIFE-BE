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
        $this->sendPayloadToUser($user, $payload, 'Merchant decision');
    }

    public function sendOrderStatusUpdate(Order $order): void
    {
        $status = (string) $order->status;

        $messages = [
            'paid' => ['Pembayaran diterima', 'Pesanan Anda telah dibayar dan sedang diproses.'],
            'responsed' => ['Pesanan diproses', 'UMKM sudah memproses pesanan Anda.'],
            'delivered' => ['Pesanan dikirim', 'Pesanan Anda sedang dalam pengiriman.'],
            'completed' => ['Pesanan selesai', 'Pesanan Anda telah selesai.'],
            'cancelled' => ['Pesanan dibatalkan', 'Pesanan Anda telah dibatalkan.'],
        ];

        if (!isset($messages[$status])) {
            return;
        }

        [$title, $body] = $messages[$status];
        $baseUrl = rtrim((string) config('app.frontend_url'), '/');

        $payloadCustomer = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/icon192.png',
            'badge' => '/icon192.png',
            'tag' => 'order-status-' . $order->id,
            'data' => [
                'url' => $baseUrl . '/orders/' . $order->id,
                'order_id' => $order->id,
                'status' => $status,
                'role' => 'customer',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($order->user) {
            $this->sendPayloadToUser($order->user, $payloadCustomer, 'Order status customer');
        }

        $merchantUser = $order->merchant?->user;
        if ($merchantUser) {
            $payloadMerchant = json_encode([
                'title' => 'Update pesanan',
                'body' => 'Status pesanan berubah menjadi ' . $status . '.',
                'icon' => '/icon192.png',
                'badge' => '/icon192.png',
                'tag' => 'order-status-' . $order->id,
                'data' => [
                    'url' => $baseUrl . '/merchant-center/' . ($order->merchant?->slug ?? '') . '/orders/' . $order->id,
                    'order_id' => $order->id,
                    'status' => $status,
                    'role' => 'merchant',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $this->sendPayloadToUser($merchantUser, $payloadMerchant, 'Order status merchant');
        }
    }

    public function sendPaymentStatusUpdate(Order $order, Payment $payment): void
    {
        $status = (string) $payment->status;
        $messages = [
            'paid' => ['Pembayaran berhasil', 'Pembayaran pesanan telah diterima.'],
            'expired' => ['Pembayaran kedaluwarsa', 'Pembayaran pesanan telah kedaluwarsa.'],
            'failed' => ['Pembayaran gagal', 'Pembayaran pesanan gagal diproses.'],
        ];

        if (!isset($messages[$status])) {
            return;
        }

        [$title, $body] = $messages[$status];
        $baseUrl = rtrim((string) config('app.frontend_url'), '/');

        $payloadCustomer = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/icon192.png',
            'badge' => '/icon192.png',
            'tag' => 'payment-status-' . $payment->id,
            'data' => [
                'url' => $baseUrl . '/orders/' . $order->id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'status' => $status,
                'role' => 'customer',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($order->user) {
            $this->sendPayloadToUser($order->user, $payloadCustomer, 'Payment status customer');
        }

        $merchantUser = $order->merchant?->user;
        if ($merchantUser) {
            $payloadMerchant = json_encode([
                'title' => 'Status pembayaran',
                'body' => 'Pembayaran pesanan sekarang ' . $status . '.',
                'icon' => '/icon192.png',
                'badge' => '/icon192.png',
                'tag' => 'payment-status-' . $payment->id,
                'data' => [
                    'url' => $baseUrl . '/merchant-center/' . ($order->merchant?->slug ?? '') . '/orders/' . $order->id,
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'status' => $status,
                    'role' => 'merchant',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $this->sendPayloadToUser($merchantUser, $payloadMerchant, 'Payment status merchant');
        }
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

    private function sendPayloadToUser(User $user, string $payload, string $context): void
    {
        $subscriptions = $user->pushSubscriptions()->get();

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
