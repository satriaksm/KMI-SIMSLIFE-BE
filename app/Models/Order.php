<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\ProductVariant;

class Order extends Model
{
    protected $fillable = [
        'address_id',
        'user_id',
        'merchant_id',
        'voucher_id',
        'order_type',
        'order_code',
        'subtotal',
        'discount_total',
        'platform_fee',
        'gross_amount',
        'net_amount',
        'delivery_fee_snapshot',
        'delivery_type',
        'payment_method',
        'payment_status',
        'notes',

        'status',
        'accepted_at',
        'rejected_at',
        'paid_at',
        'delivered_at',
        'completed_at',
        'cancelled_at',
        'ready_to_pickup_at',
        'unpicked_at',
        'confirm_deadline',

        'proof_image_path',
        'failed_reason',

        'user_name_snapshot',
        'user_phone_snapshot',
        'address_detail_snapshot',
        'province_name_snapshot',
        'city_name_snapshot',
        'district_name_snapshot',
        'village_name_snapshot',
        'latitude_snapshot',
        'longitude_snapshot',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'paid_at' => 'datetime',
        'delivered_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'ready_to_pickup_at' => 'datetime',
        'unpicked_at' => 'datetime',
        'confirm_deadline' => 'datetime',
    ];

    protected static function booted()
    {
        static::updated(function (Order $order) {
            if ($order->wasChanged('status') && in_array($order->status, ['cancelled', 'rejected'])) {
                $inventoryUpdates = [];
                $order->loadMissing('productItems');
                
                foreach ($order->productItems as $item) {
                    if ($item->product_variant_id) {
                        ProductVariant::query()
                            ->where('id', $item->product_variant_id)
                            ->update([
                                'stock' => \Illuminate\Support\Facades\DB::raw('stock + ' . $item->quantity),
                            ]);

                        $inventoryUpdates[] = [
                            'product_id' => $item->product_id,
                            'variant_id' => $item->product_variant_id,
                        ];
                    }
                }

                foreach ($inventoryUpdates as $update) {
                    $stock = (int) ProductVariant::query()
                        ->where('id', $update['variant_id'])
                        ->value('stock');

                    event(new \App\Events\InventoryStockUpdated(
                        (int) $update['product_id'],
                        (int) $update['variant_id'],
                        $stock
                    ));
                }

                if ($order->voucher_id) {
                    \App\Models\VoucherUsage::where('order_id', $order->id)->delete();
                }
            }
        });
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function items()
    {
        return $this->hasMany(ProductOrderItem::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }
    
    protected $appends = ['proof_image_url'];

    public function getProofImageUrlAttribute()
    {
        if ($this->proof_image_path) {
            return url('api/order-proofs/' . $this->id);
        }
        return null;
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

    public function getItemsTextAttribute(): string
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
