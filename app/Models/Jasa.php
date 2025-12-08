<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Jasa extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'vendor',
        'price',
        'image',
        'rating',
        'distance_km',
        'duration_hours',
        'description',
        'is_active', // 🆕 tambahkan untuk kontrol aktif/tidak
    ];

    public function packages()
    {
        return $this->hasMany(Package::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
