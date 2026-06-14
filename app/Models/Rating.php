<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Rating extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'merchant_id',
        'order_id',
        'order_item_id',
        'jasa_order_item_id',
        'service_order_id',
        'rateable_id',
        'rateable_type',
        'rating',
        'title',
        'comment',
        'is_anonymous',
        'update_count',
        'review_updated_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_anonymous' => 'boolean',
        'update_count' => 'integer',
        'review_updated_at' => 'datetime',
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

    /**
     * Order reference (for Toko/Kuliner)
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Order item reference (for Toko/Kuliner - per item review)
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * Service order reference (for Jasa)
     */
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /**
     * Jasa order item reference (for Jasa - links to unified orders table)
     */
    public function jasaOrderItem(): BelongsTo
    {
        return $this->belongsTo(JasaOrderItem::class);
    }

    /**
     * Media untuk review ini (foto/video)
     */
    public function media(): HasMany
    {
        return $this->hasMany(ReviewMedia::class, 'review_id')->orderBy('display_order');
    }

    // ===== SCOPES =====

    public function scopeForMerchant($query, $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeForProduct($query, int $productId)
    {
        return $query->where('rateable_type', Product::class)->where('rateable_id', $productId);
    }

    public function scopeForJasa($query, int $jasaId)
    {
        return $query->where('rateable_type', Jasa::class)->where('rateable_id', $jasaId);
    }

    public function scopeForOrder($query, int $orderId)
    {
        return $query->where('order_id', $orderId);
    }

    public function scopeForServiceOrder($query, int $serviceOrderId)
    {
        return $query->where('service_order_id', $serviceOrderId);
    }

    public function scopeForOrderItem($query, int $orderItemId)
    {
        return $query->where('order_item_id', $orderItemId);
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

    public function scopeNotAnonymous($query)
    {
        return $query->where('is_anonymous', false);
    }

    // ===== ACCESSORS =====

    public function getRatingPercentageAttribute()
    {
        return round(($this->rating / 5) * 100);
    }

    /**
     * Get reviewer display name.
     * Returns 'Anonim' if the review is marked as anonymous.
     * Returns user name or 'Pengguna' as fallback.
     */
    public function getReviewerNameAttribute()
    {
        if ($this->is_anonymous) {
            return 'Anonim';
        }
        return $this->user?->name ?? 'Pengguna';
    }

    // ===== SERIALIZATION =====

    /**
     * Always include reviewer_name and is_anonymous in JSON responses.
     * Also includes the user relation when loaded.
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        // Always include reviewer_name (uses accessor which handles is_anonymous)
        $array['reviewer_name'] = $this->reviewer_name;
        $array['is_anonymous'] = (bool) $this->is_anonymous;

        // Include update metadata for frontend UX
        $array['update_count'] = (int) ($this->update_count ?? 0);
        $array['review_updated_at'] = $this->review_updated_at;
        $array['can_update'] = $this->canUpdate();
        $array['is_update_exhausted'] = $this->isUpdateExhausted();

        return $array;
    }

    // ===== HELPER METHODS =====

    /**
     * Check if this review is for a service order
     */
    public function isForServiceOrder(): bool
    {
        return $this->service_order_id !== null;
    }

    /**
     * Check if this review is for a product order
     */
    public function isForProductOrder(): bool
    {
        return $this->order_id !== null;
    }

    /**
     * Check if the review can still be updated.
     * Returns true if update_count < 1.
     */
    public function canUpdate(): bool
    {
        return ($this->update_count ?? 0) < 1;
    }

    /**
     * Check if the review update has already been used.
     * Returns true if update_count >= 1.
     */
    public function isUpdateExhausted(): bool
    {
        return ($this->update_count ?? 0) >= 1;
    }

    /**
     * Get remaining update count (max 1).
     */
    public function getRemainingUpdateCount(): int
    {
        return max(0, 1 - ($this->update_count ?? 0));
    }
}
