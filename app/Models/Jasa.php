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
        'fixed_price',
        'base_price',
        'service_type',
        'location_address',
        'service_area',
        'special_notes',
        'payment_methods',
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

    public function images()
    {
        return $this->hasMany(JasaImage::class);
    }
}
