<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    protected $table = 'provinces';
    protected $fillable = ['name'];
    public $timestamps = false; // ✅ Tambahkan ini jika tabel tidak punya created_at/updated_at

    // ✅ Tambahkan relasi ke cities
    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'province_id');
    }

    // ✅ Tambahkan relasi ke addresses
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'province_id');
    }
}
