<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promo extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'title',
        'desc',
        'type',
        'value',
        'is_active' // 🆕 promo aktif/tidak
    ];
}
