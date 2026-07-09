<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Events\OrderStatusUpdated;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoExpireMerchantResponseOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:auto-expire-merchant-response';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire jasa orders where merchant has not responded within 24 hours';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Running: AutoExpireMerchantResponseOrders...');

        $expiredOrders = Order::where('order_type', 'jasa')
            ->where('status', 'menunggu_konfirmasi_merchant')
            ->whereNotNull('confirm_deadline')
            ->whereNull('merchant_responded_at')
            ->where('confirm_deadline', '<=', now())
            ->get();

        $this->info("Found {$expiredOrders->count()} orders to expire.");

        $count = 0;
        foreach ($expiredOrders as $order) {
            $previousStatus = $order->status;

            $order->update([
                'status' => 'expired',
                'expired_at' => now(),
            ]);

            event(new OrderStatusUpdated($order->fresh(), 'expired'));

            Log::info('[AutoExpireMerchantResponseOrders] Order expired', [
                'order_id' => $order->id,
                'previous_status' => $previousStatus,
                'merchant_response_deadline' => $order->merchant_response_deadline,
            ]);

            $count++;
            $this->line("  Expired order #{$order->id}");
        }

        $this->info("Done. {$count} orders expired.");

        return Command::SUCCESS;
    }
}
