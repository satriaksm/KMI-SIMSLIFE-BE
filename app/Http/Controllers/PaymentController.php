<?php

namespace App\Http\Controllers;

use App\Events\OrderStatusUpdated;
use App\Events\PaymentStatusUpdated;
use App\Helpers\ApiResponse;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\XenditInvoiceService;
use App\Services\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\ProductVariant;

class PaymentController extends Controller
{
    public function __construct(private readonly XenditInvoiceService $xenditInvoiceService)
    {
    }

    /**
     * Create Invoice (Checkout)
     */
    public function createInvoice(Request $request, $orderId)
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $order = Order::with('user', 'merchant')
            ->where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (!$order instanceof Order) {
            return ApiResponse::error('Order tidak ditemukan', 404);
        }

        // ❗ Pastikan order belum dibayar
        if ($order->status !== 'pending') {
            return ApiResponse::error('Order tidak valid atau sudah diproses', 400);
        }

        try {
            $payment = $this->xenditInvoiceService->createOrGetPendingInvoice($order);

            return ApiResponse::success([
                'invoice_url' => $payment->invoice_url,
                'payment_id' => $payment->id
            ], 'Invoice berhasil dibuat');

        } catch (\Throwable $e) {
            return ApiResponse::error('Gagal membuat invoice', 500, $e->getMessage());
        }
    }

    /**
     * Get Payment Status (dari DB)
     */
    public function getStatus($orderId)
    {
        $user = Auth::user();
        if (!$user instanceof User) {
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
     * Digunakan FE setelah redirect balik dari Xendit (menggantikan polling webhook).
     */
    public function verifyPayment($orderId)
    {
        $user = Auth::user();
        if (!$user instanceof User) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $order = Order::with('items')
            ->where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return ApiResponse::error('Order tidak ditemukan', 404);
        }

        // Jika sudah paid/lebih, kembalikan status saja
        if (!in_array($order->status, ['pending'], true)) {
            return ApiResponse::success([
                'order_status' => $order->status,
                'already_paid' => true,
            ], 'Order sudah diproses');
        }

        $payment = Payment::query()
            ->where('order_id', $orderId)
            ->where('status', 'pending')
            ->latest()
            ->first();

        if (!$payment || !$payment->xendit_invoice_id) {
            return ApiResponse::success([
                'order_status' => $order->status,
                'already_paid' => false,
            ], 'Tidak ada invoice aktif');
        }

        // Cek langsung ke Xendit API
        try {
            $response = Http::withBasicAuth((string) config('services.xendit.secret_key'), '')
                ->acceptJson()
                ->get(rtrim((string) config('services.xendit.base_url', 'https://api.xendit.co'), '/') . '/v2/invoices/' . $payment->xendit_invoice_id);

            if (!$response->successful()) {
                return ApiResponse::error('Gagal verifikasi ke Xendit', 502);
            }

            $invoiceData = $response->json();
            $xenditStatus = strtoupper((string) ($invoiceData['status'] ?? ''));

            if ($xenditStatus !== 'PAID') {
                return ApiResponse::success([
                    'order_status'  => $order->status,
                    'xendit_status' => $xenditStatus,
                    'already_paid'  => false,
                ], 'Pembayaran belum terkonfirmasi');
            }

            // ✅ Status PAID di Xendit — update DB sekarang
            DB::transaction(function () use ($payment, $order, $invoiceData) {
                $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->first();
                if (!$lockedPayment || $lockedPayment->status === 'paid') return;

                $lockedPayment->update([
                    'status'         => 'paid',
                    'paid_at'        => now(),
                    'payment_method' => $invoiceData['payment_method'] ?? null,
                    'raw_response'   => $invoiceData,
                ]);

                $confirmMinutes = (int) config('app.order_confirm_minutes', 10);

                $order->update([
                    'status'           => 'paid',
                    'paid_at'          => now(),
                    'confirm_deadline' => now()->addMinutes($confirmMinutes),
                ]);

                // Kurangi stok
                foreach ($order->items as $item) {
                    if (!$item->product_variant_id) continue;
                    $qty = (int) $item->quantity;
                    if ($qty <= 0) continue;

                    ProductVariant::where('id', $item->product_variant_id)
                        ->update(['stock' => DB::raw('GREATEST(stock - ' . $qty . ', 0)')]);
                }
            });

            $payment->refresh();
            $order->refresh();

            // Broadcast events (real-time update ke FE merchant & customer)
            event(new PaymentStatusUpdated($payment));
            event(new OrderStatusUpdated($order));

            $webPush = app(WebPushService::class);
            $webPush->sendPaymentStatusUpdate($order, $payment);
            $webPush->sendOrderStatusUpdate($order);

            return ApiResponse::success([
                'order_status'   => $order->status,
                'already_paid'   => true,
                'payment_method' => $payment->payment_method,
                'paid_at'        => $payment->paid_at,
            ], 'Pembayaran berhasil dikonfirmasi');

        } catch (\Throwable $e) {
            return ApiResponse::error('Gagal verifikasi pembayaran: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Cancel Payment (optional)
     */
    public function cancel($orderId)
    {
        $user = Auth::user();
        if (!$user instanceof User) {
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
            event(new OrderStatusUpdated($order->fresh()));
            $webPush = app(WebPushService::class);
            $webPush->sendPaymentStatusUpdate($order, $payment);
            $webPush->sendOrderStatusUpdate($order);
        }

        return ApiResponse::success(null, 'Payment dibatalkan');
    }
}