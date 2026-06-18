<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The order instance.
     */
    public Order $order;

    /**
     * Optional status context (for backward compatibility).
     */
    public ?string $status;

    /**
     * Create a new event instance.
     *
     * @param Order $order
     * @param string|null $status (optional, for backward compatibility with jasa flow)
     */
    public function __construct(Order $order, ?string $status = null)
    {
        $this->order = $order;
        $this->status = $status;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('orders.' . $this->order->id),
        ];

        // Channel untuk customer
        if ($this->order->user_id) {
            $channels[] = new PrivateChannel('users.' . $this->order->user_id . '.orders');
        }

        // Channel untuk merchant
        if ($this->order->merchant_id) {
            $channels[] = new PrivateChannel('merchants.' . $this->order->merchant_id . '.orders');
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'order.status.updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'status' => $this->status ?? $this->order->status,
            'user_id' => $this->order->user_id,
            'merchant_id' => $this->order->merchant_id,
            'payment_status' => $this->order->payment_status ?? null,
            'paid_at' => $this->order->paid_at?->toISOString(),
            'responsed_at' => $this->order->responsed_at?->toISOString(),
            'delivered_at' => $this->order->delivered_at?->toISOString(),
            'completed_at' => $this->order->completed_at?->toISOString(),
            'cancelled_at' => $this->order->cancelled_at?->toISOString(),
            'order_type' => $this->order->order_type ?? 'product',
        ];
    }
}
