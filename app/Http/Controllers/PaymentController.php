<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\JasaOrderItem;
use App\Models\User;
use App\Helpers\ApiResponse;
use App\Events\PaymentStatusUpdated;
use App\Events\OrderStatusUpdated;
use App\Services\XenditInvoiceService;
use App\Services\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * PaymentController
 *
 * Handles Xendit payment integration for ALL orders (produk/kuliner AND jasa).
 * Menggunakan Order + JasaOrderItem sebagai struktur utama.
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
     */
    public function createInvoice(Request $request, $orderId)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $order = Order::with(['merchant', 'items'])
            ->where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        // Update payment method & channel if provided in request payload (e.g. from checkout redirect or retry)
        if ($request->has('payment_method') || $request->has('payment_channel') || $request->has('channel_code')) {
            $reqMethod = strtoupper($request->input('payment_method') ?? '');
            $reqChannel = strtoupper($request->input('payment_channel') ?? $request->input('channel_code') ?? '');
            
            $genericMethods = ['XENDIT', 'ONLINE', 'ONLINE_XENDIT', 'TRANSFER'];
            if (in_array($reqMethod, $genericMethods) && $reqChannel !== '') {
                $reqMethod = $reqChannel;
            }
            
            $updates = [];
            if ($reqMethod !== '') {
                $updates['payment_method'] = $reqMethod;
                $updates['payment_method_snapshot'] = $reqMethod;
            }
            if ($reqChannel !== '') {
                $updates['payment_channel'] = $reqChannel;
                $updates['payment_channel_snapshot'] = $reqChannel;
            }
            
            if (!empty($updates)) {
                $order->update($updates);
            }
        }

        // Pastikan order belum dibayar
        if ($order->payment_status === 'PAID' || $order->status === 'paid') {
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
     * Get Payment Status (dari DB)
     */
    public function getStatus($orderId)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $order = Order::where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return ApiResponse::error('Order tidak ditemukan', 404);
        }

        $payment = Payment::query()
            ->where('order_id', $orderId)
            ->latest()
            ->first();

        return ApiResponse::success([
            'order_status'   => $order->status,
            'status'         => $payment?->status,
            'payment_method' => $payment?->payment_method,
            'paid_at'        => $payment?->paid_at,
            'expired_at'     => $payment?->expired_at,
            'invoice_url'    => $payment?->invoice_url,
        ], 'Status payment berhasil diambil');
    }

    /**
     * Verify Payment — aktif cek ke Xendit API, update order jika sudah PAID.
     * Digunakan FE setelah redirect balik dari Xendit.
     */
    public function verifyPayment(Request $request, $orderId)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $order = Order::with(['jasaItems', 'items'])
            ->where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        $payment = Payment::where('order_id', $orderId)->latest()->first();
        if ($payment) {
            Log::info('Payment matched', [
                'external_id' => $payment->external_id,
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
            ]);
        }

        // If already PAID, return immediately
        if ($order->payment_status === 'PAID') {
            return ApiResponse::success([
                'order_id' => $order->id,
                'order_status' => $order->status,
                'payment_status' => 'PAID',
                'payment_channel' => $order->paid_channel,
                'already_paid' => true,
            ], 'Pesanan sudah dibayar');
        }

        // If no invoice reference, check DB for pending payment
        $invoiceId = $order->payment_reference;
        if (!$invoiceId) {
            $payment = Payment::where('order_id', $orderId)
                ->where('status', 'pending')
                ->latest()
                ->first();
            $invoiceId = $payment?->xendit_invoice_id;
        }

        if (!$invoiceId) {
            return ApiResponse::error('Invoice belum dibuat untuk pesanan ini', 400);
        }

        // Get invoice status from Xendit
        $invoiceData = $this->xenditInvoiceService->getInvoiceStatus($invoiceId);

        if (!$invoiceData) {
            // Fallback: check via HTTP client directly using staging-ta settings
            try {
                $response = Http::withBasicAuth((string) config('services.xendit.secret_key'), '')
                    ->acceptJson()
                    ->get(rtrim((string) config('services.xendit.base_url', 'https://api.xendit.co'), '/') . '/v2/invoices/' . $invoiceId);

                if ($response->successful()) {
                    $invoiceData = $response->json();
                }
            } catch (\Throwable $t) {
                Log::error('[PaymentController] Fallback verify HTTP client failed', ['error' => $t->getMessage()]);
            }
        }

        if (!$invoiceData) {
            return ApiResponse::error('Gagal mengambil status dari Xendit', 500);
        }

        $xenditStatus = strtoupper((string) ($invoiceData['status'] ?? ''));
        $paymentChannel = $invoiceData['payment_channel'] ?? $invoiceData['payment_method'] ?? null;

        if ($xenditStatus === 'PAID' || $xenditStatus === 'SETTLED') {
            return $this->processPaymentSuccess($order, $paymentChannel, $invoiceData);
        }

        return ApiResponse::success([
            'order_id' => $order->id,
            'order_status' => $order->status,
            'payment_status' => $order->payment_status,
            'xendit_status' => $xenditStatus,
            'already_paid' => false,
            'message' => 'Pembayaran belum selesai',
        ], 'Menunggu pembayaran');
    }

    /**
     * Process successful payment.
     * Update order, payment, dan trigger events.
     */
    protected function processPaymentSuccess(Order $order, ?string $paymentChannel, array $invoiceData = []): \Illuminate\Http\JsonResponse
    {
        $isJasa = $order->order_type === 'jasa';

        // Update order status and payment information
        $orderUpdate = [
            'payment_status' => 'PAID',
            'paid_at' => now(),
            'payment_channel' => $paymentChannel,
            'paid_channel' => $paymentChannel,
        ];

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

        // Update Payment record
        $payment = Payment::where('order_id', $order->id)->first();
        if ($payment) {
            $payment->update([
                'status' => 'PAID',
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
            'merchant_response_deadline' => $order->merchant_response_deadline?->toISOString(),
            'confirm_deadline' => $order->confirm_deadline?->toISOString(),
        ]);

        return ApiResponse::success([
            'order_id' => $order->id,
            'order_status' => $order->status,
            'payment_status' => 'PAID',
            'already_paid' => true,
            'payment_method' => $paymentChannel,
            'paid_at' => $order->paid_at?->toISOString() ?? now()->toISOString(),
            'payment_channel' => $paymentChannel,
            'merchant_response_deadline' => $order->merchant_response_deadline?->toISOString(),
            'confirm_deadline' => $order->confirm_deadline?->toISOString(),
            'refreshed' => true,
        ], 'Pembayaran berhasil');
    }

    public function cancelPayment(Request $request, $orderId)
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

        $isJasa = $order->order_type === 'jasa';

        DB::transaction(function () use ($order, $orderId, $isJasa) {
            // Expire the payment record
            $payment = Payment::where('order_id', $orderId)->first();
            if ($payment) {
                $payment->update(['status' => 'expired']);
                event(new PaymentStatusUpdated($payment));
            }

            if ($isJasa) {
                // Clear payment reference so customer can create new invoice
                $order->update([
                    'payment_status' => 'PENDING',
                    'payment_reference' => null,
                ]);
            } else {
                if (in_array($order->status, ['pending', 'responsed'], true)) {
                    $order->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                    ]);
                }
            }
        });

        $order->refresh();
        event(new OrderStatusUpdated($order, $order->status));

        // Get payment
        $payment = Payment::where('order_id', $orderId)->first();
        if ($payment) {
            $this->webPushService->sendPaymentStatusUpdate($order, $payment, 'cancel');
        }

        Log::info('[PaymentController] Payment cancelled', [
            'order_id' => $order->id,
            'is_jasa' => $isJasa,
        ]);

        return ApiResponse::success([
            'order_id' => $order->id,
            'payment_status' => $order->payment_status,
            'status' => $order->status,
        ], $isJasa ? 'Pembayaran dibatalkan. Silakan buat invoice baru.' : 'Payment dibatalkan');
    }

    /**
     * Get payment status untuk sebuah order.
     */
    public function getPaymentStatus(Request $request, $orderId)
    {
        $userId = Auth::id();

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
                'service_name' => $jasaItem?->jasa_title,
                'booking_date' => $jasaItem?->booking_date?->toDateString(),
                'booking_time' => $jasaItem?->booking_time?->format('H:i'),
            ];
        }

        return ApiResponse::success([
            'order_id' => $order->id,
            'order_status' => $order->status,
            'status' => $payment?->status,
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
            'merchant_response_deadline' => $order->merchant_response_deadline?->toISOString(),
            'service' => $serviceInfo,
        ], 'success');
    }

    /**
     * Cancel Product Order (staging-ta)
     */
    public function cancel($orderId)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $payment = Payment::query()
            ->whereHas('order', function ($query) use ($orderId, $user) {
                $query->where('id', $orderId)
                    ->where('user_id', $user->id);
            })
            ->where('status', 'pending')
            ->latest()
            ->first();

        if (!$payment) {
            return ApiResponse::error('Tidak ada pembayaran aktif', 404);
        }

        DB::transaction(function () use ($payment) {
            $lockedPayment = Payment::query()
                ->where('id', $payment->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedPayment || $lockedPayment->status !== 'pending') {
                return;
            }

            $lockedPayment->update([
                'status' => 'expired'
            ]);

            $order = $lockedPayment->order;
            if ($order && in_array($order->status, ['pending', 'responsed'], true)) {
                $order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);
            }
        });

        $payment->refresh();
        event(new PaymentStatusUpdated($payment));

        $order = $payment->order;
        if ($order) {
            event(new OrderStatusUpdated($order->fresh(), $order->status));
            $this->webPushService->sendPaymentStatusUpdate($order, $payment, 'cancel');
        }

        return ApiResponse::success(null, 'Payment dibatalkan');
    }
}
