<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Jasa extends Model
{
    use HasFactory;

    protected $fillable = [
        // Ownership
        'merchant_id',

        // Core fields
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

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
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

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function packages()
    {
        return $this->hasMany(Package::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
