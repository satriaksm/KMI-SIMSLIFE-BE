<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\JasaOrderItem;
use App\Events\PaymentStatusUpdated;
use App\Events\OrderStatusUpdated;
use App\Services\WebPushService;
use App\Services\XenditInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WebhookController
 *
 * Centralized webhook handler untuk Xendit payment callbacks.
 * HANYA menangani Order (produk/kuliner dan jasa).
 *
 * NOTE: ServiceOrder sudah deprecated. Semua order baru menggunakan Order + JasaOrderItem.
 *
 * Supported order types:
 * - Order produk/kuliner: external_id = "order-{id}"
 * - Order jasa: external_id = "order-{id}" (order_type = 'jasa')
 *
 * Flow:
 * 1. Validate callback token
 * 2. Parse external_id untuk tentukan order
 * 3. Update payment status
 * 4. Update order status + confirm_deadline (untuk jasa)
 * 5. Fire events (PaymentStatusUpdated, OrderStatusUpdated)
 * 6. Send push notification (non-blocking)
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly XenditInvoiceService $xenditInvoiceService,
        private readonly WebPushService $webPushService
    ) {
    }

    /**
     * Handle Xendit payment callback/webhook.
     * Endpoint: POST /api/payment/xendit/webhook
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function callback(Request $request)
    {
        Log::info('[WebhookController] Received webhook', [
            'external_id' => $request->input('external_id'),
            'status' => $request->input('status'),
        ]);

        // Validasi callback token
        if (!$this->xenditInvoiceService->validateCallbackToken($request->header('x-callback-token'))) {
            Log::warning('[WebhookController] Invalid callback token', [
                'ip' => $request->ip(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $data = $request->all();
        $externalId = $data['external_id'] ?? null;
        $status = strtoupper((string) ($data['status'] ?? ''));

        if (!$externalId) {
            Log::warning('[WebhookController] Missing external_id');
            return response()->json([
                'success' => false,
                'message' => 'Invalid payload'
            ], 400);
        }

        // Only handle order-{id} format
        if (str_starts_with($externalId, 'order-')) {
            return $this->handleOrderWebhook($data);
        }

        // Unknown format - ignore
        Log::info('[WebhookController] Unknown external_id format, ignored', [
            'external_id' => $externalId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ignored'
        ], 200);
    }

    /**
     * Handle webhook untuk Order (produk/kuliner dan jasa).
     *
     * @param array $data
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleOrderWebhook(array $data)
    {
        $externalId = $data['external_id'] ?? null;
        $status = strtoupper((string) ($data['status'] ?? ''));

        // Extract order ID dari external_id
        $orderId = (int) str_replace('order-', '', $externalId);

        // Find payment
        $payment = Payment::where('external_id', $externalId)->first();

        if (!$payment) {
            Log::warning('[WebhookController] Payment not found', [
                'external_id' => $externalId,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Payment not found'
            ], 404);
        }

        $order = $payment->order;

        if (!$order) {
            Log::warning('[WebhookController] Order not found', [
                'order_id' => $orderId,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        // IDEMPOTENCY: Check if already processed
        if ($this->isPaymentAlreadyProcessed($payment, $status)) {
            Log::info('[WebhookController] Payment already processed', [
                'payment_id' => $payment->id,
                'order_id' => $orderId,
                'status' => $status,
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Already processed'
            ], 200);
        }

        // Handle based on status
        switch ($status) {
            case 'PAID':
                return $this->handleOrderPaid($order, $payment, $data);

            case 'EXPIRED':
                return $this->handleOrderExpired($order, $payment, $data);

            case 'FAILED':
                return $this->handleOrderFailed($order, $payment, $data);

            default:
                Log::info('[WebhookController] Unhandled status', [
                    'payment_id' => $payment->id,
                    'order_id' => $orderId,
                    'status' => $status,
                ]);
                return response()->json([
                    'success' => true,
                    'message' => 'Unhandled status'
                ], 200);
        }
    }

    /**
     * Check if payment is already processed with this status.
     * Maps Xendit UPPERCASE status to DB lowercase ENUM values for comparison.
     */
    protected function isPaymentAlreadyProcessed(Payment $payment, string $newStatus): bool
    {
        $statusMap = [
            'PAID' => 'paid',
            'EXPIRED' => 'expired',
            'FAILED' => 'failed',
        ];

        $dbStatus = $statusMap[$newStatus] ?? null;

        if (!$dbStatus) {
            return false;
        }

        return $payment->status === $dbStatus;
    }

    /**
     * Handle PAID status untuk Order.
     * Update payment, order, dan set confirm_deadline untuk jasa.
     */
    protected function handleOrderPaid(Order $order, Payment $payment, array $data): \Illuminate\Http\JsonResponse
    {
        return DB::transaction(function () use ($order, $payment, $data) {
            // Lock payment untuk prevent race condition
            $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->first();

            if (!$lockedPayment || $lockedPayment->status === 'paid') {
                return response()->json([
                    'success' => true,
                    'message' => 'Already processed'
                ], 200);
            }

            $paymentChannel = $data['payment_channel'] ?? $data['payment_method'] ?? null;
            $isJasa = $order->order_type === 'jasa';
            $confirmMinutes = (int) config('app.order_confirm_minutes', 60);

            // Update payment (Payment.status is lowercase ENUM: pending, paid, expired, failed)
            $lockedPayment->status = 'paid';
            $lockedPayment->paid_at = now();
            $lockedPayment->payment_method = $paymentChannel;
            $lockedPayment->raw_response = $data;
            $lockedPayment->save();

            // Update order
            $order->payment_status = 'paid';
            $order->paid_at = now();
            $order->payment_channel = $paymentChannel;
            $order->paid_channel = $paymentChannel;

            // Untuk jasa, ubah status ke waiting_confirm dan set confirm_deadline
            if ($isJasa) {
                $order->status = 'menunggu_konfirmasi_merchant';
                $order->confirm_deadline = now()->addMinutes($confirmMinutes);
            } else {
                $order->status = 'paid';
            }
            $order->save();

            // Fire events
            event(new PaymentStatusUpdated($lockedPayment->fresh()));
            event(new OrderStatusUpdated($order->fresh(), $order->status));

            // Non-blocking push notification
            $this->webPushService->sendPaymentStatusUpdate($order, $lockedPayment, 'webhook');

            Log::info('[WebhookController] Order payment processed', [
                'payment_id' => $lockedPayment->id,
                'order_id' => $order->id,
                'is_jasa' => $isJasa,
                'confirm_deadline' => $order->confirm_deadline?->toISOString(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment processed'
            ], 200);
        });
    }

    /**
     * Handle EXPIRED status untuk Order.
     * Cancel order jika masih pending.
     */
    protected function handleOrderExpired(Order $order, Payment $payment, array $data): \Illuminate\Http\JsonResponse
    {
        // Payment.status is lowercase ENUM
        $payment->status = 'expired';
        $payment->raw_response = $data;
        $payment->save();

        // Cancel order jika masih pending (Order uses lowercase status)
        if (in_array($order->status, ['pending', 'menunggu_konfirmasi_merchant'])) {
            $order->status = 'batal';
            $order->cancelled_at = now();
            $order->save();

            Log::info('[WebhookController] Order expired cancelled', [
                'order_id' => $order->id,
                'confirm_deadline' => $order->confirm_deadline,
            ]);

            event(new OrderStatusUpdated($order->fresh(), 'batal'));
        }

        event(new PaymentStatusUpdated($payment->fresh()));

        if ($order) {
            $this->webPushService->sendPaymentStatusUpdate($order, $payment->fresh(), 'expired');
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment expired'
        ], 200);
    }

    /**
     * Handle FAILED status untuk Order.
     */
    protected function handleOrderFailed(Order $order, Payment $payment, array $data): \Illuminate\Http\JsonResponse
    {
        $payment->status = 'failed';
        $payment->raw_response = $data;
        $payment->save();

        event(new PaymentStatusUpdated($payment->fresh()));

        if ($order) {
            $this->webPushService->sendPaymentStatusUpdate($order, $payment->fresh(), 'failed');
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment failed'
        ], 200);
    }
}
