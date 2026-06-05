<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Events\OrderStatusUpdated;
use App\Models\MerchantWalletHistory;
use App\Models\Order;
use App\Models\Payment;
use App\Services\WebPushService;
use Illuminate\Support\Facades\DB;

class AutoCancelExpiredOrders extends Command
{
    protected $signature = 'orders:auto-cancel-expired';
    protected $description = 'Auto cancel expired unpaid orders and orders past UMKM confirm deadline';

    public function handle()
    {
        $cancelled = 0;

        // 1. Payment expired (Transfer belum bayar)
        $expiredPayments = Payment::where('status', 'pending')
            ->where('expired_at', '<', now())
            ->get();

        foreach ($expiredPayments as $payment) {
            DB::transaction(function () use ($payment) {
                $payment->update(['status' => 'expired']);

                $order = $payment->order;
                if ($order && $order->status === 'pending') {
                    $order->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                    ]);

                    $freshOrder = $order->fresh();
                    $payment->refresh();
                    event(new OrderStatusUpdated($freshOrder));
                    $webPush = app(WebPushService::class);
                    $webPush->sendPaymentStatusUpdate($freshOrder, $payment);
                }
            });
            $cancelled++;
        }

        // 2. Confirm deadline lewat (UMKM tidak konfirmasi)
        $deadlineOrders = Order::whereNotNull('confirm_deadline')
            ->where('confirm_deadline', '<', now())
            ->whereIn('status', ['pending', 'paid'])
            ->get();

        foreach ($deadlineOrders as $order) {
            DB::transaction(function () use ($order) {
                // Refund balance_pending jika order sudah dibayar (Transfer)
                if ($order->status === 'paid' && $order->payment_method === 'Transfer' && $order->paid_at) {
                    $merchant = $order->merchant;
                    if ($merchant) {
                        $netAmount = (float) ($order->net_amount ?? 0);
                        if ($netAmount <= 0) {
                            $netAmount = max(0, (float) $order->gross_amount - (float) $order->platform_fee);
                        }

                        $currentPending = (float) $merchant->balance_pending;
                        $refundAmount = min($netAmount, $currentPending);

                        if ($refundAmount > 0) {
                            $merchant->decrement('balance_pending', $refundAmount);

                            MerchantWalletHistory::create([
                                'merchant_id'    => $merchant->id,
                                'type'           => 'refund',
                                'amount'         => $refundAmount,
                                'reference_type' => 'order',
                                'reference_id'   => $order->id,
                                'description'    => 'Auto-cancel — UMKM did not confirm in time',
                            ]);
                        }
                    }
                }

                $order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);

                event(new OrderStatusUpdated($order->fresh()));
                app(WebPushService::class)->sendOrderStatusUpdate($order->fresh(), 'auto');
            });
            $cancelled++;
        }

        $this->info("Auto-cancelled {$cancelled} expired/deadline orders.");
    }
}