<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JasaImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'jasa_id',
        'path',
        'is_cover',
    ];

    protected $casts = [
        'is_cover' => 'boolean',
    ];

    public function jasa()
    {
        return $this->belongsTo(Jasa::class);
    }
}
