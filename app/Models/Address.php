<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Address extends Model
{
    use HasFactory;

    protected $table = 'addresses';

    protected $fillable = [
        'addressable_id',
        'addressable_type',
        'province_id',
        'city_id',
        'district_id',
        'village_id',
        'detail',
        'label',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    // ✅ Relasi Polimorfik ke User/Merchant
    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }

    // ✅ Relasi ke Province
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_id');
    }

    // ✅ Relasi ke City (Regency)
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    // ✅ Relasi ke District
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    // ✅ Relasi ke Village
    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_id');
    }

    // ✅ Accessor untuk alamat lengkap
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->detail,
            $this->village?->name,
            $this->district?->name,
            $this->city?->name,
            $this->province?->name,
        ]);

        return implode(', ', $parts);
    }
}
