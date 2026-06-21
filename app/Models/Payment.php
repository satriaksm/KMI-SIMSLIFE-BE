<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $table = 'payments';

    protected $fillable = [
        'order_id',
        'external_id',
        'xendit_invoice_id',
        'invoice_url',
        'payment_method',
        'status',
        'amount',
        'paid_at',
        'paid_channel',
        'expired_at',
        'raw_response',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'expired_at' => 'datetime',
        'amount' => 'decimal:2',
        'raw_response' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'PAID';
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function getDisplayMethod(): string
    {
        if ($this->status === 'PAID' && $this->payment_method) {
            return 'Xendit - ' . $this->payment_method;
        }
        if ($this->payment_method) {
            return 'Xendit - ' . $this->payment_method;
        }
        return 'Xendit';
    }
}
