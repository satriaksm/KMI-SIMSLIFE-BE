<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class EventMerchant extends Pivot
{
    protected $table = 'event_merchants';

    protected $fillable = [
        'merchant_id',
        'event_id',
        'status',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }
}