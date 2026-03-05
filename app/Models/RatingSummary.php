<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RatingSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'rateable_id',
        'rateable_type',
        'average_rating',
        'total_ratings',
        'rating_1',
        'rating_2',
        'rating_3',
        'rating_4',
        'rating_5',
    ];

    protected $casts = [
        'average_rating' => 'float',
        'total_ratings' => 'integer',
        'rating_1' => 'integer',
        'rating_2' => 'integer',
        'rating_3' => 'integer',
        'rating_4' => 'integer',
        'rating_5' => 'integer',
    ];

    // ===== RELATIONSHIPS =====

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function rateable(): MorphTo
    {
        return $this->morphTo();
    }

    // ===== STATIC METHODS FOR CACHE UPDATE =====

    /**
     * Update atau buat rating summary saat ada rating baru
     */
    public static function updateFromRating(Rating $rating)
    {
        $summary = self::firstOrCreate(
            [
                'merchant_id' => $rating->merchant_id,
                'rateable_id' => $rating->rateable_id,
                'rateable_type' => $rating->rateable_type,
            ]
        );

        // Hitung total rating dan breakdown
        $ratings = Rating::where('merchant_id', $rating->merchant_id)
            ->where('rateable_id', $rating->rateable_id)
            ->where('rateable_type', $rating->rateable_type)
            ->get();

        $total = $ratings->count();
        $sum = $ratings->sum('rating');
        $average = $total > 0 ? round($sum / $total, 2) : 0;

        $summary->update([
            'average_rating' => $average,
            'total_ratings' => $total,
            'rating_5' => $ratings->where('rating', 5)->count(),
            'rating_4' => $ratings->where('rating', 4)->count(),
            'rating_3' => $ratings->where('rating', 3)->count(),
            'rating_2' => $ratings->where('rating', 2)->count(),
            'rating_1' => $ratings->where('rating', 1)->count(),
        ]);

        return $summary;
    }

    /**
     * Tambah total rating merchant (untuk akumulasi keseluruhan)
     */
    public static function updateMerchantOverall($merchantId)
    {
        $ratings = Rating::where('merchant_id', $merchantId)->get();

        $total = $ratings->count();
        $sum = $ratings->sum('rating');
        $average = $total > 0 ? round($sum / $total, 2) : 0;

        return [
            'average_rating' => $average,
            'total_ratings' => $total,
            'rating_5' => $ratings->where('rating', 5)->count(),
            'rating_4' => $ratings->where('rating', 4)->count(),
            'rating_3' => $ratings->where('rating', 3)->count(),
            'rating_2' => $ratings->where('rating', 2)->count(),
            'rating_1' => $ratings->where('rating', 1)->count(),
        ];
    }
}
