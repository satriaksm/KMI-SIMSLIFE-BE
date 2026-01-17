<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class District extends Model
{
    protected $table = 'districts';
    protected $fillable = ['name', 'city_id'];
    public $timestamps = false; // ✅ Tambahkan jika tidak ada timestamps

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    // ✅ Tambahkan relasi ke villages
    public function villages(): HasMany
    {
        return $this->hasMany(Village::class, 'district_id');
    }

    // ✅ Tambahkan relasi ke addresses
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'district_id');
    }
}
