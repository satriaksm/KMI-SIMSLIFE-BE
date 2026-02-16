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
        // Ownership
        'merchant_id',
        'title',
        'vendor',
        'price',
        'image',
        'rating',
        'distance_km',
        'duration_hours',
        'description',

        // Merchant jasa fields
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
}
