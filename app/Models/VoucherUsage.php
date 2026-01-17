<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherUsage extends Model
{
    protected $fillable = [
        'user_id',
        'voucher_id',
        'order_id',
        'discount_amount',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // Check if user can use voucher
    public static function canUseVoucher(int $userId, int $voucherId, Voucher $voucher): bool
    {
        $usageCount = static::where('user_id', $userId)
            ->where('voucher_id', $voucherId)
            ->count();

        return $usageCount < $voucher->usage_limit_per_user;
    }
}