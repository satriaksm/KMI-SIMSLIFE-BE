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
        'summaryable_id',
        'summaryable_type',
        'average_rating',
        'total_reviews',
        'total_ratings',
        'rating_1_count',
        'rating_2_count',
        'rating_3_count',
        'rating_4_count',
        'rating_5_count',
        'rating_1',
        'rating_2',
        'rating_3',
        'rating_4',
        'rating_5',
    ];

    protected $casts = [
        'average_rating' => 'float',
        'total_reviews' => 'integer',
        'total_ratings' => 'integer',
        'rating_1_count' => 'integer',
        'rating_2_count' => 'integer',
        'rating_3_count' => 'integer',
        'rating_4_count' => 'integer',
        'rating_5_count' => 'integer',
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

    /**
     * Legacy polymorphic relationship (uses rateable_id/rateable_type)
     */
    public function rateable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Polymorphic relationship for polymorphic summaries (uses summaryable_id/summaryable_type)
     */
    public function summaryable(): MorphTo
    {
        return $this->morphTo();
    }

    // ===== HELPER ATTRIBUTES =====

    /**
     * Get total_reviews (alias for total_ratings)
     */
    public function getTotalReviewsAttribute(): int
    {
        return $this->total_reviews ?? $this->total_ratings ?? 0;
    }

    // ===== STATIC METHODS FOR CACHE UPDATE =====

    /**
     * Update atau buat rating summary saat ada rating baru (legacy approach)
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

        $ratings = Rating::where('merchant_id', $rating->merchant_id)
            ->where('rateable_id', $rating->rateable_id)
            ->where('rateable_type', $rating->rateable_type)
            ->get();

        $total = $ratings->count();
        $sum = $ratings->sum('rating');
        $average = $total > 0 ? round($sum / $total, 2) : 0;

        $summary->update([
            'average_rating' => $average,
            'total_reviews' => $total,
            'total_ratings' => $total,
            'rating_5_count' => $ratings->where('rating', 5)->count(),
            'rating_4_count' => $ratings->where('rating', 4)->count(),
            'rating_3_count' => $ratings->where('rating', 3)->count(),
            'rating_2_count' => $ratings->where('rating', 2)->count(),
            'rating_1_count' => $ratings->where('rating', 1)->count(),
        ]);

        return $summary;
    }

    /**
     * Update polymorphic rating summary for a specific model (Jasa, Product, Merchant)
     */
    /**
     * Update polymorphic rating summary for a specific model (Jasa, Product, Merchant)
     */
    public static function updatePolymorphicSummary($model, ?int $merchantId = null)
    {
        $modelClass = get_class($model);
        $modelId = $model->id;

        // Get all ratings for this model
        $ratings = Rating::where('rateable_id', $modelId)
            ->where('rateable_type', $modelClass)
            ->get();

        $total = $ratings->count();
        $sum = $ratings->sum('rating');
        $average = $total > 0 ? round($sum / $total, 2) : 0;

        // Cari summary dari format baru atau legacy agar tidak membuat duplicate
        $summary = self::where(function ($q) use ($modelId, $modelClass) {
            $q->where('summaryable_id', $modelId)
                ->where('summaryable_type', $modelClass);
        })->orWhere(function ($q) use ($modelId, $modelClass) {
            $q->where('rateable_id', $modelId)
                ->where('rateable_type', $modelClass);
        })->first();

        if (!$summary) {
            $summary = new self();
        }

        // Isi kedua format supaya kompatibel dengan query lama dan baru
        $summary->merchant_id = $merchantId;
        $summary->summaryable_id = $modelId;
        $summary->summaryable_type = $modelClass;
        $summary->rateable_id = $modelId;
        $summary->rateable_type = $modelClass;

        $summary->average_rating = $average;
        $summary->total_reviews = $total;
        $summary->total_ratings = $total;

        $summary->rating_5_count = $ratings->where('rating', 5)->count();
        $summary->rating_4_count = $ratings->where('rating', 4)->count();
        $summary->rating_3_count = $ratings->where('rating', 3)->count();
        $summary->rating_2_count = $ratings->where('rating', 2)->count();
        $summary->rating_1_count = $ratings->where('rating', 1)->count();

        $summary->save();

        return $summary;
    }

    /**
     * Update merchant overall rating summary (all reviews for the merchant)
     */
    public static function updateMerchantOverall($merchantId)
    {
        $merchant = Merchant::find($merchantId);
        if (!$merchant) {
            return [
                'average_rating' => 0,
                'total_reviews' => 0,
                'rating_5_count' => 0,
                'rating_4_count' => 0,
                'rating_3_count' => 0,
                'rating_2_count' => 0,
                'rating_1_count' => 0,
            ];
        }

        $query = Rating::where('merchant_id', $merchantId);

        // Filter ratings based on merchant segmentation type to avoid cross-UMKM reviews
        if ($merchant->segmentation_id == 3) {
            $query->where('rateable_type', 'App\\Models\\Jasa');
        } else {
            $query->where('rateable_type', 'App\\Models\\Product');
        }

        $ratings = $query->get();

        $total = $ratings->count();
        $sum = $ratings->sum('rating');
        $average = $total > 0 ? round($sum / $total, 2) : 0;

        return [
            'average_rating' => $average,
            'total_reviews' => $total,
            'rating_5_count' => $ratings->where('rating', 5)->count(),
            'rating_4_count' => $ratings->where('rating', 4)->count(),
            'rating_3_count' => $ratings->where('rating', 3)->count(),
            'rating_2_count' => $ratings->where('rating', 2)->count(),
            'rating_1_count' => $ratings->where('rating', 1)->count(),
        ];
    }

    /**
     * Update merchant overall rating summary by slug
     */
    public static function updateMerchantOverallBySlug(string $merchantSlug)
    {
        $merchant = Merchant::where('slug', $merchantSlug)->first();

        if (!$merchant) {
            return [
                'average_rating' => 0,
                'total_reviews' => 0,
                'rating_5_count' => 0,
                'rating_4_count' => 0,
                'rating_3_count' => 0,
                'rating_2_count' => 0,
                'rating_1_count' => 0,
            ];
        }

        return self::updateMerchantOverall($merchant->id);
    }

    /**
     * Update merchant's overall summary as a polymorphic record
     */
    public static function updateMerchantPolymorphicSummary(Merchant $merchant)
    {
        return self::updatePolymorphicSummary($merchant, $merchant->id);
    }

    // ===== FALLBACK STATIC METHODS =====

    /**
     * Calculate average rating for merchant directly from ratings table (fallback)
     */
    public static function calculateAverageForMerchant(int $merchantId): float
    {
        $avg = Rating::where('merchant_id', $merchantId)->avg('rating');
        return round($avg ?? 0, 1);
    }

    /**
     * Count total reviews for merchant directly from ratings table (fallback)
     */
    public static function countReviewsForMerchant(int $merchantId): int
    {
        return Rating::where('merchant_id', $merchantId)->count();
    }

    /**
     * Get rating summary for a product (from polymorphic summary or fallback)
     */
    public static function getProductRatingSummary(int $productId): array
    {
        // Try polymorphic summary first (new approach)
        $summary = self::where('summaryable_id', $productId)
            ->where('summaryable_type', Product::class)
            ->first(['average_rating', 'total_reviews', 'total_ratings']);

        if (!$summary) {
            // Fallback to rateable
            $summary = self::where('rateable_id', $productId)
                ->where('rateable_type', Product::class)
                ->first(['average_rating', 'total_reviews', 'total_ratings']);
        }

        if ($summary) {
            return [
                'average_rating' => round($summary->average_rating ?? 0, 1),
                'total_reviews' => $summary->total_reviews ?? $summary->total_ratings ?? 0,
            ];
        }

        // Fallback: calculate from ratings table
        $ratings = Rating::where('rateable_id', $productId)
            ->where('rateable_type', Product::class)
            ->get();

        $total = $ratings->count();
        $avg = $total > 0 ? round($ratings->avg('rating'), 1) : 0;

        return [
            'average_rating' => $avg,
            'total_reviews' => $total,
        ];
    }

    /**
     * Get rating summary for a jasa/service (from polymorphic summary or fallback)
     */
    public static function getJasaRatingSummary(int $jasaId): array
    {
        // Try polymorphic summary first
        $summary = self::where('summaryable_id', $jasaId)
            ->where('summaryable_type', Jasa::class)
            ->first(['average_rating', 'total_reviews', 'total_ratings']);

        if (!$summary) {
            // Fallback to rateable
            $summary = self::where('rateable_id', $jasaId)
                ->where('rateable_type', Jasa::class)
                ->first(['average_rating', 'total_reviews', 'total_ratings']);
        }

        if ($summary) {
            return [
                'average_rating' => round($summary->average_rating ?? 0, 1),
                'total_reviews' => $summary->total_reviews ?? $summary->total_ratings ?? 0,
            ];
        }

        // Fallback: calculate from ratings table
        $ratings = Rating::where('rateable_id', $jasaId)
            ->where('rateable_type', Jasa::class)
            ->get();

        $total = $ratings->count();
        $avg = $total > 0 ? round($ratings->avg('rating'), 1) : 0;

        return [
            'average_rating' => $avg,
            'total_reviews' => $total,
        ];
    }
}