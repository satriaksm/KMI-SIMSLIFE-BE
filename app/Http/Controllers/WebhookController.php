<?php

namespace App\Http\Controllers;

use App\Events\OrderStatusUpdated;
use App\Events\PaymentStatusUpdated;
use App\Helpers\ApiResponse;
use App\Models\Order;
use App\Models\Payment;
use App\Models\MerchantWalletHistory;
use App\Models\Payout;
use App\Services\XenditInvoiceService;
use App\Services\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WebhookController
 *
 * Centralized webhook handler untuk Xendit callbacks (payment callbacks AND payout callbacks).
 * Menangani order produk/kuliner, order jasa, dan penarikan dana (payout).
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly XenditInvoiceService $xenditInvoiceService,
        private readonly WebPushService $webPushService
    ) {
    }

    /**
     * Handle Xendit callback/webhook.
     * Endpoint: POST /api/payment/xendit/webhook
     */
    public function callback(Request $request)
    {
        // 🔐 1. VALIDASI CALLBACK TOKEN
        $callbackToken = (string) $request->header('x-callback-token', '');
        $expectedToken = (string) config('services.xendit.callback_token', '');

        if ($expectedToken === '' || !hash_equals($expectedToken, $callbackToken)) {
            Log::warning('[WebhookController] Invalid callback token', [
                'ip' => $request->ip(),
            ]);
            return ApiResponse::error('Unauthorized', 403);
        }

        $data = $request->all();
        $externalId = $data['external_id'] ?? null;

        if (!$externalId) {
            Log::warning('[WebhookController] Missing external_id');
            return ApiResponse::error('Invalid payload', 400);
        }

        Log::info('Xendit webhook received', $data);

        Log::info('[WebhookController] Received webhook', [
            'external_id' => $externalId,
            'status' => $data['status'] ?? null,
        ]);

        // 🔹 HANDLE PAYMENT (order-xxx)
        if (str_starts_with($externalId, 'order-')) {
            return $this->handlePaymentWebhook($data);
        }

        // 🔹 HANDLE PAYOUT (payout-xxx)
        if (str_starts_with($externalId, 'payout-')) {
            return $this->handlePayoutWebhook($data);
        }

        Log::info('[WebhookController] Unknown external_id format, ignored', [
            'external_id' => $externalId,
        ]);

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
            Log::warning('[WebhookController] Payment not found', [
                'external_id' => $externalId,
            ]);
            return ApiResponse::error('Payment not found', 404);
        }

        Log::info('Payment matched', [
            'external_id' => $externalId,
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
        ]);

        // ❗ IDEMPOTENCY (ANTI DOUBLE TRIGGER)
        $processedStatusMap = [
            'paid' => 'PAID',
            'expired' => 'EXPIRED',
            'failed' => 'FAILED',
        ];
        $currentStatus = strtolower((string) $payment->status);
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
            Log::warning('[WebhookController] Order not found', [
                'external_id' => $externalId,
            ]);
            return ApiResponse::error('Order not found', 404);
        }

        if ((int) round((float) $amount) !== (int) round((float) $payment->amount)) {
            Log::warning('[WebhookController] Invalid amount in callback', [
                'callback_amount' => $amount,
                'payment_amount' => $payment->amount,
            ]);
            return ApiResponse::error('Invalid amount', 400);
        }

        // Handle based on status
        if ($status === 'PAID') {
            $allowedStatuses = $order->order_type === 'jasa'
                ? ['pending', 'menunggu_konfirmasi', 'menunggu_konfirmasi_merchant']
                : ['pending'];

            if (!in_array($order->status, $allowedStatuses, true)) {
                Log::warning('[WebhookController] Invalid order status state for PAID', [
                    'order_id' => $order->id,
                    'status' => $order->status,
                ]);
                return ApiResponse::error('Invalid order state', 400);
            }

            return $this->handlePaymentPaid($order, $payment, $data);
        }

        if ($status === 'EXPIRED') {
            return $this->handlePaymentExpired($order, $payment, $data);
        }

        if ($status === 'FAILED') {
            return $this->handlePaymentFailed($order, $payment, $data);
        }

        return ApiResponse::success(null, 'Unhandled status');
    }

    /**
     * Process PAID state for Order payment.
     */
    private function handlePaymentPaid(Order $order, Payment $payment, array $data)
    {
        return DB::transaction(function () use ($data, $payment, $order) {
            $lockedPayment = Payment::where('id', $payment->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedPayment || strtolower((string) $lockedPayment->status) === 'paid') {
                return ApiResponse::success(null, 'Already processed');
            }

            $paymentChannel = $data['payment_channel'] ?? $data['payment_method'] ?? null;
            $isJasa = $order->order_type === 'jasa';

            // 1. UPDATE PAYMENT
            $lockedPayment->update([
                'status' => 'PAID',
                'paid_at' => now(),
                'payment_method' => $paymentChannel,
                'raw_response' => $data,
            ]);

            // 2. UPDATE ORDER
            $orderUpdate = [
                'payment_status' => 'PAID',
                'paid_at' => now(),
                'payment_channel' => $paymentChannel,
                'paid_channel' => $paymentChannel,
            ];

            // Recalculate platform fee based on actual payment channel used on Xendit
            if ($paymentChannel) {
                $feeCode = 'VA'; // Default
                $vaMethods = ['BCA', 'BNI', 'BRI', 'MANDIRI', 'PERMATA', 'CIMB'];
                $ewallet15 = ['OVO', 'DANA', 'LINKAJA'];
                
                $upperChannel = strtoupper($paymentChannel);
                if ($upperChannel === 'QRIS') {
                    $feeCode = 'QRIS';
                } elseif (in_array($upperChannel, $ewallet15)) {
                    $feeCode = 'EWALLET';
                } elseif ($upperChannel === 'SHOPEEPAY') {
                    $feeCode = 'SHOPEEPAY';
                } elseif ($upperChannel === 'ALFAMART' || $upperChannel === 'INDOMARET') {
                    $feeCode = 'RETAIL';
                }

                $feeConfig = \App\Models\PaymentFee::where('method_code', $feeCode)->first();
                if ($feeConfig) {
                    $baseGross = max(0, (float) $order->subtotal - (float) $order->discount_total + (float) $order->delivery_fee_snapshot);
                    if ($feeConfig->type === 'percentage') {
                        $actualPlatformFee = (int) ceil($baseGross * ($feeConfig->value / 100));
                    } else {
                        $actualPlatformFee = (int) $feeConfig->value;
                    }
                    $orderUpdate['platform_fee'] = $actualPlatformFee;
                    // Note: Since gross_amount is fixed by the invoice paid, we adjust net_amount
                    $orderUpdate['net_amount'] = max(0, (float) $order->gross_amount - $actualPlatformFee);
                }
            }

            if ($isJasa) {
                $orderUpdate['status'] = 'menunggu_konfirmasi_merchant';
                $orderUpdate['confirm_deadline'] = now()->addMinutes(60);
                $orderUpdate['merchant_response_deadline'] = now()->addMinutes(60);
            } else {
                $orderUpdate['status'] = 'paid';
                $confirmMinutes = (int) config('app.order_confirm_minutes', 10);
                $orderUpdate['confirm_deadline'] = now()->addMinutes($confirmMinutes);
            }

            $order->update($orderUpdate);

            Log::info('Order updated after paid', [
                'order_id' => $order->id,
                'order_type' => $order->order_type,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
            ]);

            // 3. For product orders, increment balance_pending and record wallet history
            if (!$isJasa) {
                $merchant = $order->merchant;
                if ($merchant) {
                    $netAmount = (float) ($order->net_amount ?? 0);
                    if ($netAmount <= 0) {
                        $netAmount = max(0, (float) $order->gross_amount - (float) $order->platform_fee);
                    }

                    $merchant->increment('balance_pending', $netAmount);

                    MerchantWalletHistory::create([
                        'merchant_id' => $merchant->id,
                        'type' => 'credit',
                        'amount' => $netAmount,
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                        'description' => 'Payment received (pending)',
                    ]);
                }
            }

            // Trigger events
            event(new PaymentStatusUpdated($lockedPayment->fresh()));
            event(new OrderStatusUpdated($order->fresh(), $order->status));

            // Web push notification
            $this->webPushService->sendPaymentStatusUpdate($order, $lockedPayment, 'webhook');

            Log::info('[WebhookController] Payment webhook success processed', [
                'payment_id' => $lockedPayment->id,
                'order_id' => $order->id,
                'is_jasa' => $isJasa,
                'merchant_response_deadline' => $order->merchant_response_deadline?->toISOString(),
            ]);

            return ApiResponse::success(null, 'Payment processed');
        });
    }

    /**
     * Process EXPIRED state for Order payment.
     */
    private function handlePaymentExpired(Order $order, Payment $payment, array $data)
    {
        $payment->update([
            'status' => 'expired',
            'raw_response' => $data,
        ]);

        $isJasa = $order->order_type === 'jasa';
        $cancelStatus = $isJasa ? 'dibatalkan' : 'cancelled';

        if (in_array($order->status, ['pending', 'menunggu_konfirmasi_merchant'])) {
            $order->update([
                'status' => $cancelStatus,
                'cancelled_at' => now(),
            ]);
        }

        $payment->refresh();
        event(new PaymentStatusUpdated($payment));
        event(new OrderStatusUpdated($order->fresh(), $order->status));

        $this->webPushService->sendPaymentStatusUpdate($order, $payment, 'expired');

        Log::info('[WebhookController] Payment expired callback processed', [
            'order_id' => $order->id,
            'status' => $order->status,
        ]);

        return ApiResponse::success(null, 'Payment expired');
    }

    /**
     * Process FAILED state for Order payment.
     */
    private function handlePaymentFailed(Order $order, Payment $payment, array $data)
    {
        $payment->update([
            'status' => 'failed',
            'raw_response' => $data,
        ]);

        $payment->refresh();
        event(new PaymentStatusUpdated($payment));

        $this->webPushService->sendPaymentStatusUpdate($order, $payment, 'failed');

        Log::info('[WebhookController] Payment failed callback processed', [
            'order_id' => $order->id,
        ]);

        return ApiResponse::success(null, 'Payment failed');
    }

    /**
     * HANDLE PAYOUT WEBHOOK (staging-ta payout callback)
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
