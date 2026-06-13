<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\ProductOrderItem;
use App\Models\JasaOrderItem;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

/**
 * PaymentController
 *
 * Handles Xendit payment integration for produk/kuliner AND jasa orders.
 *
 * Flow:
 * 1. Order created (payment_status = PENDING/UNPAID)
 * 2. Frontend calls POST /api/payments/{orderId}/invoice to create Xendit invoice
 * 3. Backend returns invoice_url for redirect to Xendit
 * 4. Customer pays via Xendit
 * 5. Xendit webhook updates payment_status to PAID
 * 6. Frontend redirects back and fetches updated order
 */
class PaymentController extends Controller
{
    /**
     * Create Xendit invoice for an existing order.
     *
     * Supports both product and jasa orders.
     *
     * @param Request $request
     * @param int $orderId Order ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function createInvoice(Request $request, int $orderId)
    {
        $userId = Auth::id();

        // Find order - must belong to current user
        $order = Order::with(['merchant', 'productItems', 'jasaItems.jasa'])
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->first();

        if (!$order) {
            return ApiResponse::error('Pesanan tidak ditemukan', 404);
        }

        // Check if order is still in valid state for payment
        if ($order->payment_status === 'PAID') {
            return ApiResponse::error('Pesanan sudah dibayar', 400);
        }

        // Check if invoice already exists (idempotent)
        if ($order->payment_reference) {
            // Return existing invoice URL
            return ApiResponse::success([
                'order_id' => $order->id,
                'invoice_id' => $order->payment_reference,
                'invoice_url' => null,
                'payment_status' => $order->payment_status,
                'message' => 'Invoice sudah pernah dibuat',
            ], 'Invoice sudah tersedia');
        }

        // Get order description based on type
        $description = $this->getOrderDescription($order);

        // Use order_{id} as external_id - XenditWebhookController handles routing based on order_type
        $externalId = 'order_' . $order->id;

        // Create Xendit invoice
        $invoiceData = XenditWebhookController::createInvoice(
            externalId: $externalId,
            amount: (int) $order->total_price,
            description: $description,
            customer: [
                'email' => $userId ? Auth::user()?->email : null,
                'name' => $order->nama ?? $order->user?->name ?? 'Customer',
            ]
        );

        Log::info('[PaymentController] Creating Xendit invoice', [
            'order_id' => $order->id,
            'order_type' => $order->order_type,
            'external_id' => $externalId,
            'amount' => $order->total_price,
            'invoice_data_exists' => !empty($invoiceData),
        ]);

        if ($invoiceData && !empty($invoiceData['invoice_url'])) {
            // Update order with invoice info
            $order->update([
                'payment_status' => 'WAITING_CONFIRMATION',
                'payment_reference' => $invoiceData['id'] ?? null,
            ]);

            Log::info('[PaymentController] Xendit invoice created successfully', [
                'order_id' => $order->id,
                'invoice_id' => $invoiceData['id'] ?? null,
                'invoice_url' => $invoiceData['invoice_url'] ?? null,
            ]);

            return ApiResponse::success([
                'order_id' => $order->id,
                'invoice_id' => $invoiceData['id'] ?? null,
                'invoice_url' => $invoiceData['invoice_url'],
                'payment_status' => 'WAITING_CONFIRMATION',
            ], 'Invoice berhasil dibuat');
        }

        Log::error('[PaymentController] Failed to create Xendit invoice', [
            'order_id' => $order->id,
            'invoice_data' => $invoiceData,
        ]);

        return ApiResponse::error('Gagal membuat invoice pembayaran', 500);
    }

    /**
     * Get order description based on order type.
     */
    private function getOrderDescription(Order $order): string
    {
        // Jasa order
        if ($order->order_type === 'jasa') {
            $jasaItem = $order->jasaItems->first();
            $serviceName = $jasaItem?->jasa?->title ?? 'Layanan Jasa';
            return "Pembayaran Layanan: {$serviceName}";
        }

        // Product order
        $productItems = $order->productItems;
        $itemCount = $productItems->count();
        $itemNames = $productItems->take(3)->map(fn($item) => $item->product?->name ?? 'Produk')->implode(', ');
        $remainingCount = $itemCount - 3;

        if ($itemCount > 3) {
            return "Pembayaran {$itemNames} dan {$remainingCount} item lainnya";
        }
        return "Pembayaran {$itemNames}";
    }

    /**
     * Verify payment status for an order.
     *
     * @param Request $request
     * @param int $orderId Order ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyPayment(Request $request, int $orderId)
    {
        $userId = Auth::id();

        $order = Order::where('id', $orderId)
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
        if (!$order->payment_reference) {
            return ApiResponse::error('Invoice belum dibuat untuk pesanan ini', 400);
        }

        // Get invoice status from Xendit
        $invoiceData = XenditWebhookController::getInvoiceStatus($order->payment_reference);

        if (!$invoiceData) {
            return ApiResponse::error('Gagal mengambil status dari Xendit', 500);
        }

        $xenditStatus = $invoiceData['status'] ?? null;
        $paymentChannel = $invoiceData['payment_channel'] ?? null;

        if ($xenditStatus === 'PAID' || $xenditStatus === 'SETTLED') {
            // Update order to PAID
            $order->update([
                'payment_status' => 'PAID',
                'paid_at' => now(),
                'payment_channel' => $paymentChannel,
                'paid_channel' => $paymentChannel,
            ]);

            Log::info('[PaymentController] Payment verified as PAID', [
                'order_id' => $order->id,
                'invoice_id' => $order->payment_reference,
                'payment_channel' => $paymentChannel,
            ]);

            return ApiResponse::success([
                'order_id' => $order->id,
                'payment_status' => 'PAID',
                'payment_channel' => $paymentChannel,
                'refreshed' => true,
            ], 'Pembayaran berhasil');
        }

        return ApiResponse::success([
            'order_id' => $order->id,
            'payment_status' => $order->payment_status,
            'xendit_status' => $xenditStatus,
            'message' => 'Pembayaran belum selesai',
        ], 'Menunggu pembayaran');
    }

    /**
     * Cancel/expiring invoice for an order.
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

        // If already PAID, can't cancel
        if ($order->payment_status === 'PAID') {
            return ApiResponse::error('Pesanan sudah dibayar, tidak dapat dibatalkan', 400);
        }

        // Clear payment reference so customer can create new invoice
        $order->update([
            'payment_status' => 'PENDING',
            'payment_reference' => null,
        ]);

        Log::info('[PaymentController] Payment cancelled', [
            'order_id' => $order->id,
        ]);

        return ApiResponse::success([
            'order_id' => $order->id,
            'payment_status' => 'PENDING',
        ], 'Pembayaran dibatalkan. Silakan buat invoice baru.');
    }

    /**
     * Get payment status for an order.
     *
     * @param Request $request
     * @param int $orderId Order ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPaymentStatus(Request $request, int $orderId)
    {
        $userId = Auth::id();

        $order = Order::where('id', $orderId)
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

        return ApiResponse::success([
            'order_id' => $order->id,
            'payment_method' => $order->payment_method,
            'payment_method_display' => $paymentMethodLabels[strtoupper($order->payment_method ?? '')]
                ?? $order->payment_method
                ?? '-',
            'payment_status' => $order->payment_status,
            'payment_status_display' => $paymentStatusLabels[strtoupper($order->payment_status ?? '')]
                ?? $order->payment_status
                ?? '-',
            'payment_channel' => $order->payment_channel,
            'paid_channel' => $order->paid_channel,
            'has_invoice' => !empty($order->payment_reference),
            'invoice_id' => $order->payment_reference,
        ], 'success');
    }
}