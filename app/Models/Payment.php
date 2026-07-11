<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'external_id',
        'xendit_invoice_id',
        'xendit_refund_id',
        'invoice_url',
        'payment_method',
        'expired_at',
        'amount',
        'status',
        'refund_status',
        'paid_at',
        'raw_response',
        'refund_destination',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expired_at' => 'datetime',
        'paid_at' => 'datetime',
        'raw_response' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
