<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryStockUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $productId,
        public int $variantId,
        public int $stock
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('products.' . $this->productId);
    }

    public function broadcastAs(): string
    {
        return 'inventory.stock.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'product_id' => $this->productId,
            'variant_id' => $this->variantId,
            'stock' => $this->stock,
        ];
    }
}
