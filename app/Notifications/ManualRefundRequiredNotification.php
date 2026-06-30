<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ManualRefundRequiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function via(object $notifiable): array
    {
        return ['database']; // Keeping it strictly in the DB to avoid email spam as requested by the plan
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'manual_refund_required',
            'title'       => 'Refund Manual Diperlukan',
            'message'     => 'Pesanan ' . $this->order->order_code . ' telah dibatalkan namun metode pembayaran tidak mendukung auto-refund.',
            'order_id'    => $this->order->id,
            'order_code'  => $this->order->order_code,
            'invoice_id'  => $this->order->payment?->xendit_invoice_id,
            'amount'      => (float) $this->order->payment?->amount,
            'action_url'  => '/admin/refunds',
        ];
    }
}
