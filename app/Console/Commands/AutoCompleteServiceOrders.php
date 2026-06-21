<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Events\OrderStatusUpdated;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoCompleteServiceOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:auto-complete-service';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-complete jasa orders where customer has not confirmed within 24 hours of evidence upload';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Running: AutoCompleteServiceOrders...');

        $customerConfirmHours = (int) config('sla.customer_confirm_hours', 24);
        $this->info("Customer confirm SLA: {$customerConfirmHours} hours");

        $toComplete = Order::where('order_type', 'jasa')
            ->where('status', 'menunggu_selesai')
            ->whereNotNull('completion_deadline_at')
            ->whereNull('completed_at')
            ->where('completion_deadline_at', '<=', now())
            ->get();

        $this->info("Found {$toComplete->count()} orders to auto-complete.");

        $count = 0;
        foreach ($toComplete as $order) {
            $previousStatus = $order->status;

            $order->update([
                'status' => 'selesai',
                'completed_at' => now(),
                'auto_completed_at' => now(),
                'completed_by' => 'system',
            ]);

            event(new OrderStatusUpdated($order->fresh(), 'selesai'));

            Log::info('[AutoCompleteServiceOrders] Order auto-completed', [
                'order_id' => $order->id,
                'previous_status' => $previousStatus,
                'completion_submitted_at' => $order->completion_submitted_at,
            ]);

            $count++;
            $this->line("  Auto-completed order #{$order->id}");
        }

        $this->info("Done. {$count} orders auto-completed.");

        return Command::SUCCESS;
    }
}
