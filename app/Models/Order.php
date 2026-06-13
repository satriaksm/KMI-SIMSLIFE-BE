<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use App\Events\InventoryStockUpdated;

class Order extends Model
{
    protected $fillable = [
        // Common fields
        'user_id',
        'merchant_id',
        'order_type', // PRIMARY: jenis order utama (jasa, product)
        'status',

        // Legacy fields (still in table, for backward compatibility with existing data)
        // NOTE: For new orders, DO NOT write these fields - use product_order_items or jasa_order_items instead
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

        // =================================================================
        // PERTANJUKAN DARI STAGING-TA (PRODUK/KULINER)
        // =================================================================
        'address_id',
        'voucher_id',

        // Snapshot fields from staging-ta
        'user_name_snapshot',
        'user_phone_snapshot',
        'address_detail_snapshot',
        'province_name_snapshot',
        'city_name_snapshot',
        'district_name_snapshot',
        'village_name_snapshot',
        'latitude_snapshot',
        'longitude_snapshot',

        // Timestamps from staging-ta
        'responsed_at',
        'accepted_at',
        'rejected_at',
        'delivered_at',
        'completed_at',
        'cancelled_at',
        'confirm_deadline',

        // =================================================================
        // CRITICAL: jasa_id is NO LONGER accepted via mass assignment
        // For new orders, use jasa_order_items.jasa_id instead
        // =================================================================
    ];

    protected $casts = [
        // Timestamps from staging-ta
        'responsed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'paid_at' => 'datetime',
        'delivered_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'confirm_deadline' => 'datetime',
    ];

    protected $appends = [
        'proof_image_url',
        'order_type',
        'items',
    ];

    // =================================================================
    // BOOTED METHOD DARI STAGING-TA (PRODUK/KULINER)
    // =================================================================
    protected static function booted(): void
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
                                'stock' => DB::raw('stock + ' . $item->quantity),
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

                    event(new InventoryStockUpdated(
                        (int) $update['product_id'],
                        (int) $update['variant_id'],
                        $stock
                    ));
                }
            }
        });
    }

    // =================================================================
    // RELATIONS DARI STAGING-TA (PRODUK/KULINER)
    // =================================================================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    // =================================================================
    // RELATIONS DARI FEAT/JASA-BARU (JASA)
    // =================================================================

    public function items(): HasMany
    {
        return $this->hasMany(ProductOrderItem::class);
    }

    public function productItems(): HasMany
    {
        return $this->hasMany(ProductOrderItem::class);
    }

    public function jasaItems(): HasMany
    {
        return $this->hasMany(JasaOrderItem::class);
    }

    // =================================================================
    // ACCESSORS DARI STAGING-TA (PRODUK/KULINER)
    // =================================================================

    public function getProofImageUrlAttribute(): ?string
    {
        if ($this->proof_image_path) {
            return url('api/order-proofs/' . $this->id);
        }
        return null;
    }

    // =================================================================
    // ACCESSORS DARI FEAT/JASA-BARU (JASA)
    // =================================================================

    /**
     * Get order type with zero-downtime compatibility.
     * Priority:
     * 1. Use stored order_type if available
     * 2. Check if has jasaItems -> 'jasa'
     * 3. Default to 'product'
     */
    public function getOrderTypeAttribute(): string
    {
        $storedType = $this->attributes['order_type'] ?? null;

        if (is_string($storedType) && $storedType !== '') {
            return $storedType;
        }

        // Check if order has jasaItems -> it's a jasa order
        return $this->jasaItems()->exists() ? 'jasa' : 'product';
    }

    /**
     * Get items text representation.
     * Checks productItems first, then jasaItems, then legacy items.
     */
    public function getItemsTextAttribute(): string
    {
        // Check product items first
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

        // Check jasa items
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

        // Check legacy items (OrderItem)
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

    /**
     * Alias for getItemsTextAttribute() - backward compatibility
     */
    public function getItemsAttribute(): string
    {
        return $this->getItemsTextAttribute();
    }
}
