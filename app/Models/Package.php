<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'jasa_id',
        'name',
        'price',
        'image'
    ];

    public function jasa()
    {
        return $this->belongsTo(Jasa::class);
    }
}
