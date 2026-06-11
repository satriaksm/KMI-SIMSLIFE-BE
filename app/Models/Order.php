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
        // Common fields
        'user_id',
        'merchant_id',
        'order_type', // PRIMARY: mekanisme pemesanan (consultation, direct_checkout, booking)
        'status',

        // Legacy fields (still in table, for backward compatibility with existing data)
        // NOTE: For new orders, DO NOT write these fields - use jasa_order_items instead
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
        'package_id',

        // Payment fields
        'payment_method',
        'payment_status',
        'payment_channel',
        'paid_channel',
        'payment_reference',
        'paid_at',

        // Price fields
        'total_price',
        'subtotal',
        'discount_total',
        'delivery_fee_snapshot',
        'platform_fee',
        'gross_amount',
        'net_amount',
        'delivery_type',

        // Other
        'notes',
        'order_code',
        'proof_image_path',
        'failed_reason',

        // CRITICAL: jasa_id is NO LONGER accepted via mass assignment
        // For new orders, use jasa_order_items.jasa_id instead
    ];

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
