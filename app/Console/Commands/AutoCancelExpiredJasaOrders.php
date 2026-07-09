<?php

namespace App\Console\Commands;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoCancelExpiredJasaOrders extends Command
{
    protected $signature = 'jasa:auto-cancel-expired';
    protected $description = 'Auto-cancel expired unpaid jasa orders and orders past merchant confirm deadline';

    public function handle()
    {
        $cancelled = 0;

        // 1. Payment expired (Xendit invoice not paid before expiry)
        // Only cancel jasa orders - product/kuliner has its own flow
        // Note: Payment uses UPPERCASE status (PENDING, PAID, EXPIRED, FAILED)
        $expiredPayments = Payment::where('status', 'PENDING')
            ->where('expired_at', '<', now())
            ->whereHas('order', fn($q) => $q->where('order_type', 'jasa'))
            ->with('order')
            ->get();

        foreach ($expiredPayments as $payment) {
            $order = $payment->order;

            // Only cancel if order is still in payment-pending state
            if (!$order || $order->order_type !== 'jasa') continue;
            if (!in_array($order->status, ['pending', 'menunggu_konfirmasi_merchant'])) continue;

            DB::transaction(function () use ($payment, $order) {
                // Update payment status (UPPERCASE)
                $payment->status = 'EXPIRED';
                $payment->save();

                // Update order status (use 'batal' which is the valid ENUM value)
                $order->status = 'batal';
                $order->cancelled_at = now();
                $order->save();

                Log::info('[AutoCancelJasa] Payment expired, order cancelled', [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                ]);

                event(new OrderStatusUpdated($order->fresh(), 'batal'));
            });

            $cancelled++;
        }

        // 2. Confirm deadline passed (merchant did not respond in time)
        // Only for jasa orders in 'menunggu_konfirmasi_merchant' status
        $deadlineOrders = Order::where('order_type', 'jasa')
            ->where('status', 'menunggu_konfirmasi_merchant')
            ->whereNotNull('confirm_deadline')
            ->where('confirm_deadline', '<', now())
            ->get();

        foreach ($deadlineOrders as $order) {
            DB::transaction(function () use ($order) {
                // Update order status (use 'batal' which is the valid ENUM value)
                $order->status = 'batal';
                $order->cancelled_at = now();
                $order->save();

                Log::info('[AutoCancelJasa] Confirm deadline passed, order cancelled', [
                    'order_id' => $order->id,
                    'confirm_deadline' => $order->confirm_deadline,
                ]);

                event(new OrderStatusUpdated($order->fresh(), 'batal'));
            });

            $cancelled++;
        }

        $this->info("Auto-cancelled {$cancelled} expired/deadline jasa orders.");
    }
}
