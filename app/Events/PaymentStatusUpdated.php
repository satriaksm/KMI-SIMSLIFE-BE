<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Payment $payment)
    {
    }

    public function broadcastOn(): array
    {
        $order = $this->payment->order;
        $channels = [];

        if ($order) {
            $channels[] = new PrivateChannel('orders.' . $order->id);

            if ($order->user_id) {
                $channels[] = new PrivateChannel('users.' . $order->user_id . '.orders');
            }

            if ($order->merchant_id) {
                $channels[] = new PrivateChannel('merchants.' . $order->merchant_id . '.orders');
            }
        }

        // Broadcast payment status to payment channel
        if ($this->payment->id) {
            $channels[] = new PrivateChannel('payments.' . $this->payment->id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'payment.status.updated';
    }

    public function broadcastWith(): array
    {
        $order = $this->payment->order;

        return [
            'payment_id' => $this->payment->id,
            'order_id' => $order?->id,
            'status' => $this->payment->status,
            'payment_method' => $this->payment->payment_method,
            'paid_at' => $this->payment->paid_at?->toISOString(),
            'expired_at' => $this->payment->expired_at?->toISOString(),
            'amount' => $this->payment->amount,
            'invoice_url' => $this->payment->invoice_url,
            'xendit_invoice_id' => $this->payment->xendit_invoice_id,
        ];
    }
}
