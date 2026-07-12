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

    protected $fillable = [
        'merchant_id',
        'title',
        'slug',
        'description',
        'fixed_price',
        'base_price',
        'delivery_type',
        'location_address',
        'special_notes',
        'payment_methods',
        'status',
        'operating_days',
        'operating_times',

        // Flags
        'cara_pemesanan', // langsung_pesan | booking | memerlukan_konsultasi (UMKM Jasa only)
    ];

    protected $appends = ['image_url', 'cover_img'];

    /**
     * Accessor: $jasa->image_url
     * Prioritas: polymorphic coverImage > legacy image field
     */
    public function getImageUrlAttribute(): ?string
    {
        // Try polymorphic cover image first
        if ($this->coverImage) {
            return asset('storage/' . $this->coverImage->image_path);
        }

        // Fall back to legacy image field
        if ($this->image) {
            return asset('storage/' . $this->image);
        }

        return null;
    }

    protected $casts = [
        'operating_times' => 'array',
        'operating_days' => 'array',
        'payment_methods' => 'array',
        'fixed_price' => 'integer',
        'base_price' => 'integer',
    ];

    /**
     * Keep polymorphic type compatible with legacy inserts that use 'jasa'
     * in images.imageable_type.
     */
    public function getMorphClass()
    {
        return 'jasa';
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Virtual attribute returned as `cover_img` for frontend compatibility.
     * Prioritizes polymorphic images, falls back to legacy image field.
     */
    public function getCoverImgAttribute()
    {
        // First try to get from polymorphic images relationship
        $coverImage = $this->coverImage;
        if ($coverImage) {
            return (object) [
                'url' => $coverImage->url,
                'src_url' => $coverImage->src_url ?? $coverImage->url,
            ];
        }

        // Fall back to legacy image field
        if ($this->image) {
            return (object) [
                'url' => asset('storage/' . $this->image),
                'src_url' => asset('storage/' . $this->image),
            ];
        }

        return null;
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


    public function categories()
    {
        return $this->morphToMany(
            Category::class,
            'categorizable',
            'categorizables',
            'categorizable_id',
            'category_id'
        )->withTimestamps();
    }

    public function packages()
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
}