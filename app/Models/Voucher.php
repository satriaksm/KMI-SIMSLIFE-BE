<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    protected $fillable = [
        'merchant_id',
        'event_id',
        'voucher_name',
        'voucher_code',
        'voucher_status',
        'voucher_type',
        'voucher_description',
        'voucher_start_date',
        'voucher_end_date',
        'value',
        'max_discount_amount',
        'min_purchase_amount',
        'usage_limit_per_user',
        'usage_limit',
    ];

    protected $casts = [
        'voucher_start_date' => 'date',
        'voucher_end_date' => 'date',
        'value' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'min_purchase_amount' => 'decimal:2',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function usages()
    {
        return $this->hasMany(VoucherUsage::class);
    }
    protected $appends = ['usage', 'is_expired'];

    public function getUsageAttribute()
    {
        if ($this->usage_limit === null) {
            return "{$this->usages_count} / ∞";
        }

        return "{$this->usages_count} / {$this->usage_limit}";
    }

    public function scopeActive($query)
    {
        return $query->where('voucher_status', 'active')
            ->where('voucher_start_date', '<=', now())
            ->where('voucher_end_date', '>=', now());
    }


    public function getIsExpiredAttribute(): bool
    {
        return Carbon::parse($this->voucher_end_date)->isPast();
    }
}