<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
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
        return $this->belongsTo(Jasa::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
