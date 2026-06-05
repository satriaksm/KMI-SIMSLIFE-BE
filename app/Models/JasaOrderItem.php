<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JasaOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'jasa_id',
        'user_id',
        'package_id',
        'nama',
        'tel',
        'alamat',
        'catatan',
        'catatan_alamat',
        'tanggal',
        'waktu',
        'metode_pembayaran',
        'promo_code',
        'total',
        'status',
    ];

    public function jasa()
    {
        return $this->belongsTo(\App\Models\Jasa::class);
    }

    public function package()
    {
        return $this->belongsTo(\App\Models\Package::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
