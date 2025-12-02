<?php

namespace App\Models;

use App\Models\Addon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Merchant extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'paguyuban_id',
        'segmentation_id',
        'name',
        'description',
        'logo_path',
        'status',
        'response_at',
    ];

    // Relasi ke user
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(Addon::class, 'merchant_id');
    }

    // Relasi ke paguyuban
    public function paguyuban(): BelongsTo
    {
        return $this->belongsTo(Paguyuban::class);
    }

    // Relasi ke segmentation
    public function segmentation(): BelongsTo
    {
        return $this->belongsTo(Segmentation::class);
    }

    // Banyak alamat (polimorfik)
    public function addresses(): MorphMany
    {
        return $this->morphMany(\App\Models\Adrress::class, 'addressable');
    }

    // Alamat utama (opsional)
    public function primaryAddress(): MorphOne
    {
        return $this->morphOne(\App\Models\Adrress::class, 'addressable')->latestOfMany();
    }

}
