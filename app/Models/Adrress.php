<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Adrress extends Model
{
    use HasFactory;

    protected $table = 'addresses';

    protected $fillable = [
        'province_id',
        'city_id',
        'district_id',
        'village_id',
        'latitude',
        'longitude',
        'detail',
        'label',
        // 'addressable_id', 'addressable_type', // aktifkan jika perlu mass-assign
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo('App\Models\Province', 'province_id');
    }

    public function regency(): BelongsTo
    {
        return $this->belongsTo('App\Models\Regency', 'regency_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo('App\Models\District', 'district_id');
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo('App\Models\Village', 'village_id');
    }
}
