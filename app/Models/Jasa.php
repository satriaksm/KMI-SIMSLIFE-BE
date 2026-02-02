<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Jasa extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'title',
        'description',
        'fixed_price',
        'base_price',
        'service_type',
        'location_address',
        'service_area',
        'special_notes',
        'payment_methods',
        'operating_days',
        'operating_times',
        'status',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        // Removed legacy casts that may decode non-JSON values
        // 'operating_days' => 'array',
        // 'social_media' => 'array',
    ];

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

    public function packages()
    {
        return $this->hasMany(Package::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}
