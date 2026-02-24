<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Rating extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'merchant_id',
        'rateable_id',
        'rateable_type',
        'rating',
        'title',
        'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ===== RELATIONSHIPS =====

    /**
     * Pemberi rating (Customer/User)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * UMKM yang diratingkan
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Polymorph: bisa Product atau Jasa
     */
    public function rateable(): MorphTo
    {
        return $this->morphTo();
    }

    // ===== SCOPES =====

    public function scopeForMerchant($query, $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeWithRating($query, int $minRating = null, int $maxRating = null)
    {
        if ($minRating !== null) {
            $query->where('rating', '>=', $minRating);
        }
        if ($maxRating !== null) {
            $query->where('rating', '<=', $maxRating);
        }
        return $query;
    }

    // ===== ACCESSORS =====

    public function getRatingPercentageAttribute()
    {
        return round(($this->rating / 5) * 100);
    }
}
