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
        'xendit_refund_id',
        'invoice_url',
        'payment_method',
        'status',
        'refund_status',
        'amount',
        'paid_at',
        'paid_channel',
        'expired_at',
        'raw_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'expired_at' => 'datetime',
        'raw_response' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isPaid(): bool
    {
        return strtoupper((string) $this->status) === 'PAID';
    }

    public function isPending(): bool
    {
        return strtoupper((string) $this->status) === 'PENDING';
    }

    public function getDisplayMethod(): string
    {
        if ($this->payment_method) {
            return 'Xendit - ' . $this->payment_method;
        }

        return 'Xendit';
    }
}
