<?php

namespace App\Http\Controllers;

use App\Events\InventoryStockUpdated;
use App\Events\OrderStatusUpdated;
use App\Events\PaymentStatusUpdated;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\MerchantWalletHistory;
use App\Models\Payout;
use App\Services\WebPushService;

class WebhookController extends Controller
{
    public function callback(Request $request)
    {
        // 🔐 1. VALIDASI CALLBACK TOKEN
        $callbackToken = (string) $request->header('x-callback-token', '');
        $expectedToken = (string) config('services.xendit.callback_token', '');

        if ($expectedToken === '' || !hash_equals($expectedToken, $callbackToken)) {
            return ApiResponse::error('Unauthorized', 403);
        }

        $data = $request->all();

        $externalId = $data['external_id'] ?? null;

        if (!$externalId) {
            return ApiResponse::error('Invalid payload', 400);
        }

        // 🔹 HANDLE PAYMENT (order-xxx)
        if (str_starts_with($externalId, 'order-')) {
            return $this->handlePaymentWebhook($data);
        }

        // 🔹 HANDLE PAYOUT (payout-xxx)
        if (str_starts_with($externalId, 'payout-')) {
            return $this->handlePayoutWebhook($data);
        }

        return ApiResponse::success(null, 'Ignored');
    }

    /**
     * HANDLE PAYMENT WEBHOOK
     */
    private function handlePaymentWebhook(array $data)
    {
        $externalId = $data['external_id'] ?? null;
        $status = strtoupper((string) ($data['status'] ?? ''));
        $amount = $data['amount'] ?? null;

        if (!$externalId || $status === '' || is_null($amount)) {
            return ApiResponse::error('Invalid payload', 400);
        }

        $payment = Payment::query()->where('external_id', $externalId)->first();

        if (!$payment) {
            return ApiResponse::error('Payment not found', 404);
        }

        // ❗ IDEMPOTENCY (ANTI DOUBLE TRIGGER)
        $processedStatusMap = [
            'paid' => 'PAID',
            'expired' => 'EXPIRED',
            'failed' => 'FAILED',
        ];
        $currentStatus = (string) $payment->status;
        if (isset($processedStatusMap[$currentStatus])) {
            if ($processedStatusMap[$currentStatus] === $status) {
                return ApiResponse::success(null, 'Already processed');
            }
            if ($currentStatus === 'paid') {
                return ApiResponse::success(null, 'Ignored');
            }
        }

        $order = $payment->order;
        if (!$order) {
            return ApiResponse::error('Order not found', 404);
        }

        if ((int) round((float) $amount) !== (int) round((float) $payment->amount)) {
            return ApiResponse::error('Invalid amount', 400);
        }

        if (!in_array($order->status, ['pending'], true) && $status === 'PAID') {
            return ApiResponse::error('Invalid order state', 400);
        }

        // 🔹 HANDLE PAID
        if ($status === 'PAID') {
            $inventoryUpdates = [];

            DB::transaction(function () use ($data, $externalId, &$inventoryUpdates) {

                $payment = Payment::where('external_id', $externalId)
                    ->lockForUpdate()
                    ->first();

                if (!$payment || $payment->status === 'paid') {
                    return;
                }

                // 1. UPDATE PAYMENT
                $payment->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'payment_method' => $data['payment_method'] ?? null,
                    'raw_response' => $data,
                ]);

                $order = $payment->order;
                if (!$order) {
                    return;
                }

                // 2. UPDATE ORDER
                $confirmMinutes = (int) config('app.order_confirm_minutes', 10);

                $order->update([
                    'status'           => 'paid',
                    'paid_at'          => now(),
                    'confirm_deadline' => now()->addMinutes($confirmMinutes),
                ]);

                $order->load('items');

                foreach ($order->items as $item) {
                    if (!$item->product_variant_id) {
                        continue;
                    }

                    $quantity = (int) $item->quantity;
                    if ($quantity <= 0) {
                        continue;
                    }

                    ProductVariant::query()
                        ->where('id', $item->product_variant_id)
                        ->update([
                            'stock' => DB::raw('GREATEST(stock - ' . $quantity . ', 0)'),
                        ]);

                    $inventoryUpdates[] = [
                        'product_id' => (int) $item->product_id,
                        'variant_id' => (int) $item->product_variant_id,
                    ];
                }

                $merchant = $order->merchant;
                if (!$merchant) {
                    return;
                }

                $netAmount = (float) ($order->net_amount ?? 0);
                if ($netAmount <= 0) {
                    $netAmount = max(0, (float) $order->gross_amount - (float) $order->platform_fee);
                }

                // 3. MASUK KE BALANCE PENDING
                $merchant->increment('balance_pending', $netAmount);

                // 4. WALLET HISTORY
                MerchantWalletHistory::create([
                    'merchant_id' => $merchant->id,
                    'type' => 'credit',
                    'amount' => $netAmount,
                    'reference_type' => 'order',
                    'reference_id' => $order->id,
                    'description' => 'Payment received (pending)',
                ]);
            });

            $payment->refresh();
            event(new PaymentStatusUpdated($payment));

            $order = $payment->order;
            if ($order) {
                event(new OrderStatusUpdated($order->fresh()));

                $webPush = app(WebPushService::class);
                $webPush->sendPaymentStatusUpdate($order, $payment);
                $webPush->sendOrderStatusUpdate($order);
            }

            foreach ($inventoryUpdates as $update) {
                $stock = (int) ProductVariant::query()
                    ->where('id', $update['variant_id'])
                    ->value('stock');

                event(new InventoryStockUpdated(
                    (int) $update['product_id'],
                    (int) $update['variant_id'],
                    $stock
                ));
            }

            return ApiResponse::success(null, 'Payment processed');
        }

        // 🔹 HANDLE EXPIRED
        if ($status === 'EXPIRED') {
            $payment->update([
                'status' => 'expired',
                'raw_response' => $data,
            ]);

            if ($order->status === 'pending') {
                $order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);
            }

            $payment->refresh();
            event(new PaymentStatusUpdated($payment));
            event(new OrderStatusUpdated($order->fresh()));

            $webPush = app(WebPushService::class);
            $webPush->sendPaymentStatusUpdate($order, $payment);
            $webPush->sendOrderStatusUpdate($order);

            return ApiResponse::success(null, 'Payment expired');
        }

        // 🔹 HANDLE FAILED
        if ($status === 'FAILED') {
            $payment->update([
                'status' => 'failed',
                'raw_response' => $data,
            ]);

            $payment->refresh();
            event(new PaymentStatusUpdated($payment));

            $webPush = app(WebPushService::class);
            if ($payment->order) {
                $webPush->sendPaymentStatusUpdate($payment->order, $payment);
            }

            return ApiResponse::success(null, 'Payment failed');
        }

        return ApiResponse::success(null, 'Unhandled status');
    }

    /**
     * HANDLE PAYOUT WEBHOOK
     */
    private function handlePayoutWebhook(array $data)
    {
        $externalId = $data['external_id'] ?? null;
        $status = $data['status'] ?? null;

        if (!$externalId || !$status) {
            return ApiResponse::error('Invalid payload', 400);
        }

        $payout = Payout::query()->where('external_id', $externalId)->first();

        if (!$payout) {
            return ApiResponse::error('Payout not found', 404);
        }

        // ❗ IDEMPOTENCY
        if ($payout->status === 'success') {
            return ApiResponse::success(null, 'Already processed');
        }

        // 🔹 SUCCESS
        if ($status === 'COMPLETED') {
            DB::transaction(function () use ($payout, $data) {
                $payout = Payout::query()
                    ->where('id', $payout->id)
                    ->lockForUpdate()
                    ->first();

                if (!$payout || $payout->status === 'success') {
                    return;
                }

                $payout->update([
                    'status' => 'success',
                    'processed_at' => now(),
                ]);
            });

            return ApiResponse::success(null, 'Payout success');
        }

        // 🔹 FAILED
        if ($status === 'FAILED') {
            DB::transaction(function () use ($payout, $data) {
                $payout = Payout::query()
                    ->where('id', $payout->id)
                    ->lockForUpdate()
                    ->first();

                if (!$payout || in_array($payout->status, ['success', 'failed'])) {
                    return;
                }

                $payout->update([
                    'status' => 'failed',
                    'failure_reason' => $data['failure_reason'] ?? null,
                ]);

                $merchant = $payout->merchant;
                $merchant->increment('balance_available', $payout->amount);

                MerchantWalletHistory::create([
                    'merchant_id' => $merchant->id,
                    'type' => 'refund',
                    'amount' => $payout->amount,
                    'reference_type' => 'payout',
                    'reference_id' => $payout->id,
                    'description' => 'Payout failed, balance refunded',
                ]);
            });

            return ApiResponse::success(null, 'Payout failed');
        }

        return ApiResponse::success(null, 'Unhandled payout status');
    }
}