<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class City extends Model
{
    use HasFactory;
    protected $table = 'cities';
    protected $fillable = ['name', 'province_id'];
    public $timestamps = false; // ✅ Tambahkan jika tidak ada timestamps

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    // ✅ Tambahkan relasi ke districts
    public function districts(): HasMany
    {
        return $this->hasMany(District::class, 'city_id');
    }

    // ✅ Tambahkan relasi ke addresses
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'city_id');
    }
}
