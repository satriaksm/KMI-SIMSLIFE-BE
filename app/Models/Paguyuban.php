<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Paguyuban extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'image_path',
        'contact_info',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean', 
    ];

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}