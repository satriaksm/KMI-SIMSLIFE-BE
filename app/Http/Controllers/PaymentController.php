<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\XenditInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct(private readonly XenditInvoiceService $xenditInvoiceService) {}

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
     * Get Payment Status (optional endpoint)
     */
    public function getStatus($orderId)
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
            ->latest()
            ->first();

        if (!$payment) {
            return ApiResponse::error('Payment tidak ditemukan', 404);
        }

        return ApiResponse::success([
            'status' => $payment->status,
            'payment_method' => $payment->payment_method,
            'paid_at' => $payment->paid_at,
            'expired_at' => $payment->expired_at,
            'invoice_url' => $payment->invoice_url
        ], 'Status payment berhasil diambil');
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

        return ApiResponse::success(null, 'Payment dibatalkan');
    }
}