<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use HasFactory;
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

    public function merchantsVoucher()
    {
        return $this->belongsToMany(Merchant::class, 'voucher_merchants')
            ->withPivot(['status', 'voucher_type', 'discount_value', 'activated_at'])
            ->withTimestamps();
    }

    public function restrictedProducts()
    {
        return $this->belongsToMany(Product::class, 'voucher_merchant_products')
            ->withPivot(['merchant_id'])
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        $today = Carbon::today();

        return $query
            ->where('voucher_status', 'active')
            ->whereDate('voucher_start_date', '<=', $today)
            ->whereDate('voucher_end_date', '>=', $today);
    }
}
