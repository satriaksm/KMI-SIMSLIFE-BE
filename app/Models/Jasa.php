<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Jasa extends Model
{
    use HasFactory;

    /**
     * Hide legacy 'image' field from JSON response.
     * Frontend should use 'cover_img.src_url' instead (API URL).
     */
    protected $hidden = ['image'];

    protected $appends = ['name', 'nama'];

    protected $fillable = [
        'merchant_id',
        'title',
        'slug',
        'vendor',
        'price',
        'image',
        'rating',
        'distance_km',
        'duration_hours',
        'description',
        'fixed_price',
        'base_price',
        'service_type',
        'location_address',
        'service_area',
        'special_notes',
        'payment_methods',
        'status',
        'operating_days',
        'operating_times',

        // Flags
        'is_active', // 🆕 tambahkan untuk kontrol aktif/tidak
    ];

    /**
     * Keep polymorphic type compatible with legacy inserts that use 'jasa'
     * in images.imageable_type.
     */
    public function getMorphClass()
    {
        return 'jasa';
    }

    /**
     * Boot method to auto-generate slug
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->slug) {
                $model->slug = static::generateUniqueSlug($model->title);
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('title') && !$model->isDirty('slug')) {
                $model->slug = static::generateUniqueSlug($model->title, $model->id);
            }
        });
    }

    /**
     * Generate unique slug from title
     */
    protected static function generateUniqueSlug($title, $excludeId = null)
    {
        $slug = str($title)
            ->lower()
            ->slug('-');

        $count = 1;
        $originalSlug = $slug;

        $query = static::where('slug', $slug);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        while ($query->exists()) {
            $slug = "{$originalSlug}-{$count}";
            $count++;
            $query = static::where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
        }

        return $slug;
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
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

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')->orderBy('display_order');
    }

    public function coverImage(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable')->where('is_cover', true);
    }

    // 🆕 Rating System
    public function ratings(): MorphMany
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    public function ratingSummary(): MorphOne
    {
        return $this->morphOne(RatingSummary::class, 'rateable');
    }

    public function vouchers()
    {
        return $this->morphToMany(Voucher::class, 'item', 'voucher_merchant_items')
            ->withPivot(['merchant_id'])
            ->withTimestamps();
    }

    public function getNameAttribute()
    {
        return $this->attributes['title'] ?? null;
    }

    public function getNamaAttribute()
    {
        return $this->attributes['title'] ?? null;
    }
}
