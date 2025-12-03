<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Village extends Model
{
    protected $table = 'villages';
    protected $fillable = ['name', 'district_id'];
    public $timestamps = false; // ✅ Tambahkan jika tidak ada timestamps

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    // ✅ Tambahkan relasi ke addresses
    public function addresses(): HasMany
    {
        return $this->hasMany(Adrress::class, 'village_id');
    }
}