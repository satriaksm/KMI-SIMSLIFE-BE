<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class AutoCancelExpiredOrders extends Command
{
    protected $signature = 'orders:auto-cancel-expired';
    protected $description = 'Auto cancel expired unpaid orders';

    public function handle()
    {
        $payments = Payment::where('status', 'pending')
            ->where('expired_at', '<', now())
            ->get();

        foreach ($payments as $payment) {

            DB::transaction(function () use ($payment) {

                // update payment
                $payment->update([
                    'status' => 'expired'
                ]);

                // update order
                $payment->order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now()
                ]);
            });
        }

        $this->info('Expired orders cancelled successfully');
    }
}