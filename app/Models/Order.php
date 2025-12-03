<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'jasa_id',
        'user_id',
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
        'status' // 🆕 status pesanan: pending/proses/selesai/batal
    ];

    public function jasa()
    {
        return $this->belongsTo(Jasa::class);
    }
}

