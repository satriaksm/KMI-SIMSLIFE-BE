<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $appends = [
        'order_type',
        'items',
    ];

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
        'total_price',
        'status',
        'payment_method',
        'payment_status',
        'order_type',
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function productItems(): HasMany
    {
        return $this->hasMany(ProductOrderItem::class);
    }

    public function jasaItems(): HasMany
    {
        return $this->hasMany(JasaOrderItem::class);
    }

    public function getOrderTypeAttribute(): string
    {
        $storedType = $this->attributes['order_type'] ?? null;

        if (is_string($storedType) && $storedType !== '') {
            return $storedType;
        }

        return $this->jasa_id ? 'jasa' : 'product';
    }

    public function getItemsAttribute(): string
    {
        $productNames = $this->productItems
            ? $this->productItems->map(function ($item) {
                return $item->product?->name
                    ?? $item->product?->title
                    ?? 'Produk';
            })->filter()->values()
            : collect();

        if ($productNames->isNotEmpty()) {
            return $productNames->implode(', ');
        }

        $jasaNames = $this->jasaItems
            ? $this->jasaItems->map(function ($item) {
                return $item->jasa?->title
                    ?? $item->jasa?->name
                    ?? $item->note
                    ?? 'Layanan Jasa';
            })->filter()->values()
            : collect();

        if ($jasaNames->isNotEmpty()) {
            return $jasaNames->implode(', ');
        }

        $legacyItems = $this->relationLoaded('items') ? $this->getRelation('items') : null;
        if ($legacyItems && $legacyItems->isNotEmpty()) {
            return $legacyItems->map(function ($item) {
                return $item->product?->name
                    ?? $item->product?->title
                    ?? 'Produk';
            })->filter()->values()->implode(', ');
        }

        return '';
    }
}
