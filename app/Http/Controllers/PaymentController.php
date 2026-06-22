<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\JasaOrderItem;
use App\Helpers\ApiResponse;
use App\Events\PaymentStatusUpdated;
use App\Events\OrderStatusUpdated;
use App\Services\XenditInvoiceService;
use App\Services\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

/**
 * PaymentController
 *
 * Handles Xendit payment integration for ALL orders (produk/kuliner AND jasa).
 * Menggunakan Order + JasaOrderItem sebagai struktur utama.
 *
 * NOTE: ServiceOrder sudah deprecated. Semua order baru menggunakan Order + JasaOrderItem.
 *
 * Flow:
 * 1. Order dibuat (Order + JasaOrderItem untuk jasa)
 * 2. Frontend calls POST /api/payments/{orderId}/invoice untuk buat Xendit invoice
 * 3. Backend returns invoice_url untuk redirect ke Xendit
 * 4. Customer bayar via Xendit
 * 5. Xendit webhook update payment_status ke PAID
 * 6. Frontend redirect back dan fetch updated order
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly XenditInvoiceService $xenditInvoiceService,
        private readonly WebPushService $webPushService
    ) {
    }

    /**
     * Create Xendit invoice untuk order yang sudah ada.
     * Mendukung SEMUA tipe order: produk/kuliner dan jasa.
     *
     * @param Request $request
     * @param int $orderId Order ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function createInvoice(Request $request, int $orderId)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::error('Unauthorized', 401);
        }

        // Find order - harus milik user yang login
        // NOTE: jasaItems tidak perlu eager-load jasa relation untuk createInvoice
        // (createInvoice hanya menggunakan data dari orders table)
        $order = Order::with(['merchant', 'items'])
            ->where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        // Check if order is still in valid state for payment
        if ($order->payment_status === 'PAID') {
            return ApiResponse::error('Pesanan sudah dibayar', 400);
        }

        // COD tidak perlu invoice Xendit
        if (strtoupper($order->payment_method ?? '') === 'COD') {
            return ApiResponse::error('Pesanan COD tidak memerlukan invoice', 400);
        }

        try {
            // Use XenditInvoiceService (idempotent)
            $payment = $this->xenditInvoiceService->createOrGetPendingInvoice($order);

            // Update order payment status
            $order->update([
                'payment_status' => 'WAITING_CONFIRMATION',
                'payment_reference' => $payment->xendit_invoice_id,
            ]);

            return ApiResponse::success([
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'invoice_id' => $payment->xendit_invoice_id,
                'invoice_url' => $payment->invoice_url,
                'payment_status' => $payment->status,
                'expired_at' => $payment->expired_at?->toISOString(),
                // Fee breakdown
                'subtotal' => (float) ($order->subtotal_snapshot ?? $order->total_price ?? 0),
                'payment_fee' => (float) ($order->payment_fee_snapshot ?? $order->platform_fee_snapshot ?? 0),
                'total_payment' => (float) ($order->total_payment_snapshot ?? $order->total_price ?? 0),
                'payment_channel' => $order->payment_channel_snapshot ?? $order->payment_channel ?? null,
            ], 'Invoice berhasil dibuat');

        } catch (\Throwable $e) {
            Log::error('[PaymentController] Failed to create invoice', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return ApiResponse::error('Gagal membuat invoice pembayaran', 500, $e->getMessage());
        }
    }

    /**
     * Verify payment status untuk sebuah order.
     * Fallback ketika webhook belum diterima.
     *
     * @param Request $request
     * @param int $orderId Order ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyPayment(Request $request, int $orderId)
    {
        $userId = Auth::id();

        $order = Order::with(['jasaItems'])
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        // If already PAID, return immediately
        if ($order->payment_status === 'PAID') {
            return ApiResponse::success([
                'order_id' => $order->id,
                'payment_status' => 'PAID',
                'payment_channel' => $order->paid_channel,
                'already_paid' => true,
            ], 'Pesanan sudah dibayar');
        }

        // If no invoice reference, can't verify
        $invoiceId = $order->payment_reference;
        if (!$invoiceId) {
            $payment = Payment::where('order_id', $orderId)->first();
            $invoiceId = $payment?->xendit_invoice_id;
        }
        if (!$invoiceId) {
            return ApiResponse::error('Invoice belum dibuat untuk pesanan ini', 400);
        }

        // Get invoice status from Xendit
        $invoiceData = $this->xenditInvoiceService->getInvoiceStatus($invoiceId);

        if (!$invoiceData) {
            return ApiResponse::error('Gagal mengambil status dari Xendit', 500);
        }

        $xenditStatus = $invoiceData['status'] ?? null;
        $paymentChannel = $invoiceData['payment_channel'] ?? $invoiceData['payment_method'] ?? null;

        if ($xenditStatus === 'PAID' || $xenditStatus === 'SETTLED') {
            return $this->processPaymentSuccess($order, $paymentChannel, $invoiceData);
        }

        return ApiResponse::success([
            'order_id' => $order->id,
            'payment_status' => $order->payment_status,
            'xendit_status' => $xenditStatus,
            'message' => 'Pembayaran belum selesai',
        ], 'Menunggu pembayaran');
    }

    /**
     * Process successful payment.
     * Update order, payment, dan trigger events.
     *
     * @param Order $order
     * @param string|null $paymentChannel
     * @param array $invoiceData
     * @return \Illuminate\Http\JsonResponse
     */
    protected function processPaymentSuccess(Order $order, ?string $paymentChannel, array $invoiceData = []): \Illuminate\Http\JsonResponse
    {
        $isJasa = $order->order_type === 'jasa';
        $confirmMinutes = (int) config('app.order_confirm_minutes', 60);

        // Update order
        $orderUpdate = [
            'status' => $isJasa ? 'menunggu_konfirmasi_merchant' : 'paid',
            'payment_status' => 'PAID',
            'paid_at' => now(),
            'payment_channel' => $paymentChannel,
            'paid_channel' => $paymentChannel,
        ];

        // Set confirm_deadline untuk jasa order
        if ($isJasa) {
            $orderUpdate['confirm_deadline'] = now()->addMinutes($confirmMinutes);
        }

        $order->update($orderUpdate);

        // Update Payment record
        $payment = Payment::where('order_id', $order->id)->first();
        if ($payment) {
            $payment->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_method' => $paymentChannel,
                'raw_response' => $invoiceData,
            ]);
            event(new PaymentStatusUpdated($payment));
        }

        // Fire OrderStatusUpdated event
        event(new OrderStatusUpdated($order->fresh(), $order->status));

        // Non-blocking push notification
        $this->webPushService->sendPaymentStatusUpdate($order, $payment, 'verify');

        Log::info('[PaymentController] Payment verified as PAID', [
            'order_id' => $order->id,
            'payment_channel' => $paymentChannel,
            'is_jasa' => $isJasa,
            'confirm_deadline' => $order->confirm_deadline?->toISOString(),
        ]);

        return ApiResponse::success([
            'order_id' => $order->id,
            'payment_status' => 'PAID',
            'payment_channel' => $paymentChannel,
            'confirm_deadline' => $order->confirm_deadline?->toISOString(),
            'refreshed' => true,
        ], 'Pembayaran berhasil');
    }

    /**
     * Cancel/expiring invoice untuk sebuah order.
     *
     * @param Request $request
     * @param int $orderId Order ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelPayment(Request $request, int $orderId)
    {
        $userId = Auth::id();

        $order = Order::where('id', $orderId)
            ->where('user_id', $userId)
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        if ($order->payment_status === 'PAID') {
            return ApiResponse::error('Pesanan sudah dibayar, tidak dapat dibatalkan', 400);
        }

        // Clear payment reference so customer can create new invoice
        $order->update([
            'payment_status' => 'PENDING',
            'payment_reference' => null,
        ]);

        // Expire the payment record
        $payment = Payment::where('order_id', $orderId)->first();
        if ($payment) {
            $payment->update(['status' => 'expired']);
            event(new PaymentStatusUpdated($payment));
        }

        Log::info('[PaymentController] Payment cancelled', [
            'order_id' => $order->id,
        ]);

        return ApiResponse::success([
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
        ], 'Pembayaran dibatalkan. Silakan buat invoice baru.');
    }

    /**
     * Get payment status untuk sebuah order.
     *
     * @param Request $request
     * @param int $orderId Order ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPaymentStatus(Request $request, int $orderId)
    {
        $userId = Auth::id();

        // NOTE: Load jasaItems WITHOUT eager-loading jasa relation to avoid live data reads.
        // Use snapshot accessors instead: $jasaItem->jasa_title
        $order = Order::with(['jasaItems:id,order_id,jasa_id,jasa_title_snapshot'])
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        // Payment status display mapping
        $paymentStatusLabels = [
            'PENDING' => 'Menunggu Pembayaran',
            'WAITING_CONFIRMATION' => 'Menunggu Konfirmasi',
            'PAID' => 'Lunas / Sudah Dibayar',
            'UNPAID' => 'Belum Bayar',
        ];

        $paymentMethodLabels = [
            'COD' => 'Bayar di Tempat (COD)',
            'QRIS' => 'QRIS',
            'ONLINE_XENDIT' => 'Online (Xendit)',
            'BCA_VA' => 'BCA Virtual Account',
            'BNI_VA' => 'BNI Virtual Account',
            'BRI_VA' => 'BRI Virtual Account',
            'MANDIRI_VA' => 'Mandiri Virtual Account',
            'OVO' => 'OVO',
            'DANA' => 'DANA',
            'SHOPEEPAY' => 'ShopeePay',
            'ALFAMART' => 'Alfamart / Alfamidi',
        ];

        // Get payment record
        $payment = Payment::where('order_id', $orderId)->first();

        // Get service info for jasa orders - gunakan SNAPSHOT accessor
        $serviceInfo = null;
        if ($order->order_type === 'jasa') {
            $jasaItem = $order->jasaItems->first();
            $serviceInfo = [
                'jasa_order_item_id' => $jasaItem?->id,
                // Service name dari SNAPSHOT (jasa_title accessor: snapshot > live)
                'service_name' => $jasaItem?->jasa_title,
                'booking_date' => $jasaItem?->booking_date?->toDateString(),
                'booking_time' => $jasaItem?->booking_time?->format('H:i'),
            ];
        }

        return ApiResponse::success([
            'order_id' => $order->id,
            'payment_id' => $payment?->id,
            'payment_method' => $order->payment_method,
            'payment_method_display' => $paymentMethodLabels[strtoupper($order->payment_method ?? '')]
                ?? $order->payment_method ?? '-',
            'payment_status' => $order->payment_status,
            'payment_status_display' => $paymentStatusLabels[strtoupper($order->payment_status ?? '')]
                ?? $order->payment_status ?? '-',
            'payment_channel' => $order->payment_channel,
            'paid_channel' => $order->paid_channel,
            'paid_at' => $order->paid_at?->toISOString(),
            'has_invoice' => !empty($order->payment_reference),
            'invoice_id' => $order->payment_reference,
            'invoice_url' => $payment?->invoice_url,
            'expired_at' => $payment?->expired_at?->toISOString(),
            'confirm_deadline' => $order->confirm_deadline?->toISOString(),
            'service' => $serviceInfo,
        ], 'success');
    }
}
