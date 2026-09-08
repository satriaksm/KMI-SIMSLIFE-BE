<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Scope only completed voucher usages (order is completed / selesai or direct usage without order)
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('order_id')
              ->orWhereHas('order', function ($oq) {
                  $oq->whereIn('status', ['completed', 'selesai']);
              });
        });
    }

    // Check if user can use voucher
    public static function canUseVoucher(int $userId, int $voucherId, Voucher $voucher): bool
    {
        if ($voucher->usage_limit_per_user === null) {
            return true;
        }

        $usageCount = static::where('user_id', $userId)
            ->where('voucher_id', $voucherId)
            ->completed()
            ->count();

        return $usageCount < (int) $voucher->usage_limit_per_user;
    }

    /**
     * Get total completed usage for a voucher
     */
    public static function getTotalCompletedUsage(int $voucherId): int
    {
        return static::where('voucher_id', $voucherId)
            ->completed()
            ->count();
    }
}