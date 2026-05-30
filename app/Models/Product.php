<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Log;


class Product extends Model
{
    use HasFactory;

    protected $table = 'products';

    // ✅ Add this to check morphClass
    public function getMorphClass()
    {
        $class = parent::getMorphClass();
        Log::info('[Product] Morph class: ' . $class);
        return $class;
    }

    protected $fillable = [
        'merchant_id',
        'name',
        'slug',
        'description',
        'status',
        'min_purchase',
    ];

    protected $guarded = [
        'id',
    ];

    protected $casts = [
        'min_purchase' => 'integer',
    ];

    protected $attributes = [
        'min_purchase' => 1,
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class, 'product_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id');
    }

    public function categories(): MorphToMany
    {
        return $this->morphToMany(
            Category::class,
            'categorizable',
            'categorizables',
            'categorizable_id',
            'category_id'
        )->withTimestamps();
    }

    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')->orderBy('display_order');
    }

    public function coverImage(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable')->where('is_cover', true);
    }

    public function addonGroups(): HasMany
    {
        return $this->hasMany(AddonGroup::class, 'product_id');
    }

    // Scope untuk filter status
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeArchived($query)
    {
        return $query->where('status', 'archived');
    }

    public function cartItems()
    {
        return $this->morphMany(CartItem::class, 'itemable');
    }

    /**
     * ✅ NEW: Reports about this product
     */
    public function reports()
    {
        return $this->morphMany(ContentReport::class, 'reportable');
    }

    // 🆕 Rating System
    public function ratings(): MorphMany
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    public function ratingSummary(): MorphOne
    {
        return $this->morphOne(RatingSummary::class, 'summaryable');
    }
}
