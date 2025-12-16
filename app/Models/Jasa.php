<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Jasa extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'jasa_category_id',
        'jasa_subcategory_id',
        'title',
        'description',
        'price_type',
        'base_price',
        'min_order',
        'negotiable',
        'estimated_duration',
        'operating_hours_start',
        'operating_hours_end',
        'operating_days',
        'booking_advance_days',
        'service_type',
        'location_address',
        'service_area',
        'capacity_per_slot',
        'max_orders_per_day',
        'cancellation_policy',
        'customer_requirements',
        'special_notes',
        'portfolio',
        'social_media',
        'status',
        'internal_code',
        'priority',
        'is_featured',
        'image',
        'vendor',
        'price',
        'rating',
        'distance_km',
        'duration_hours',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'negotiable' => 'boolean',
        'is_featured' => 'boolean',
        'operating_days' => 'array',
        'social_media' => 'array',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function category()
    {
        return $this->belongsTo(JasaCategory::class, 'jasa_category_id');
    }

    public function subcategory()
    {
        return $this->belongsTo(JasaSubcategory::class, 'jasa_subcategory_id');
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
