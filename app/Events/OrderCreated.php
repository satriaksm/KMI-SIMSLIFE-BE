<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('orders.' . $this->order->id)];

        if ($this->order->user_id) {
            $channels[] = new PrivateChannel('users.' . $this->order->user_id . '.orders');
        }

        if ($this->order->merchant_id) {
            $channels[] = new PrivateChannel('merchants.' . $this->order->merchant_id . '.orders');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'status' => $this->order->status,
            'user_id' => $this->order->user_id,
            'merchant_id' => $this->order->merchant_id,
            'order_code' => $this->order->order_code,
            'gross_amount' => $this->order->gross_amount,
            'created_at' => optional($this->order->created_at)->toISOString(),
        ];
    }
}
