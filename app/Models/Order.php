<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_code',
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
        'payment_method',
        'delivery_type',
        'promo_code',
        'subtotal',
        'discount_total',
        'shipping_fee',
        'total',
        'status',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'total' => 'decimal:2',
        'tanggal' => 'date',
    ];

    protected $appends = [
        'customer_address',
    ];

    public function getCustomerAddressAttribute(): ?string
    {
        // 1. Ambil dari relasi User pelanggan (Alamat Utama / Daftar Alamat)
        if ($this->user && !empty($this->user->full_address)) {
            return $this->user->full_address;
        }

        // 2. Jika pengiriman ke alamat (delivery), gunakan alamat jika bukan alamat toko penjual
        $merchantAddr = $this->merchant?->address;
        if (!empty($this->alamat) && (!$merchantAddr || trim((string) $this->alamat) !== trim((string) $merchantAddr))) {
            return $this->alamat;
        }

        return null;
    }

    protected static function booted()
    {
        static::creating(function ($order) {
            if (empty($order->order_code)) {
                $datePrefix = 'ORD-' . now()->format('Ymd') . '-';
                do {
                    $code = $datePrefix . strtoupper(Str::random(5));
                } while (static::where('order_code', $code)->exists());
                $order->order_code = $code;
            }
            if (empty($order->payment_method) && !empty($order->metode_pembayaran)) {
                $order->payment_method = $order->metode_pembayaran;
            }
            if (empty($order->metode_pembayaran) && !empty($order->payment_method)) {
                $order->metode_pembayaran = $order->payment_method;
            }
        });
    }

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

    public function voucherUsage()
    {
        return $this->hasOne(VoucherUsage::class);
    }
}
