<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JasaOrderItem extends Model
{
    use HasFactory;

    protected $table = 'jasa_order_items';

    protected $fillable = [
        'order_id',
        'jasa_id',
        'service_consultation_id',
        'quantity',
        'price',
        'subtotal',
        'booking_date',
        'booking_time',
        'order_method', // PRIMARY: keranjang | booking | konsultasi
        // Service-specific fields
        'review_id',
        // NEW SNAPSHOT FIELDS FOR SERVICE TRANSACTIONS
        // Captures service (jasa) data at time of purchase
        // IMPORTANT: Always read from snapshot when displaying order history
        // If merchant modifies jasa after order, historical data remains intact
        // =================================================================

        // Service snapshot (jasa info at time of purchase)
        'jasa_title_snapshot',
        'jasa_image_snapshot',

        // Price snapshot
        'jasa_price_snapshot',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'booking_date' => 'date',

        // Price snapshots
        'jasa_price_snapshot' => 'decimal:2',

        // Booking fields
        'booking_date' => 'date',
    ];

    // Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function jasa(): BelongsTo
    {
        return $this->belongsTo(Jasa::class);
    }


    public function serviceConsultation(): BelongsTo
    {
        return $this->belongsTo(ServiceConsultation::class, 'service_consultation_id');
    }

    public function consultation(): BelongsTo
    {
        return $this->serviceConsultation();
    }

    /**
     * Completion evidences linked to this order item.
     * Menggunakan jasa_order_item_id sebagai foreign key utama.
     */
    public function completionEvidences(): HasMany
    {
        return $this->hasMany(ServiceCompletionEvidence::class, 'jasa_order_item_id');
    }

    /**
     * Review for this order item
     */
    public function review(): HasOne
    {
        return $this->hasOne(Rating::class, 'jasa_order_item_id');
    }

    // =================================================================
    // SNAPSHOT ACCESSORS - IMPORTANT FOR ORDER HISTORY
    // Always read from snapshot first, fall back to live data
    // =================================================================

    /**
     * Get jasa title - prioritizes snapshot over live data
     */
    public function getJasaTitleAttribute(): ?string
    {
        return $this->jasa_title_snapshot
            ?? $this->jasa?->title
            ?? $this->jasa?->name
            ?? null;
    }

    /**
     * Get jasa description - prioritizes snapshot over live data
     */
    public function getJasaDescriptionAttribute(): ?string
    {
        return $this->jasa_description_snapshot
            ?? $this->jasa?->description
            ?? null;
    }

    /**
     * Get jasa image URL - prioritizes snapshot over live data
     */
    public function getJasaImageUrlAttribute(): ?string
    {
        // Snapshot image path
        if ($this->jasa_image_snapshot) {
            if (str_starts_with($this->jasa_image_snapshot, 'http')) {
                return $this->jasa_image_snapshot;
            }
            return asset('storage/' . $this->jasa_image_snapshot);
        }

        // Fall back to live jasa cover image
        if ($this->jasa?->coverImage) {
            return $this->jasa->coverImage->url;
        }

        // Fall back to jasa image_url accessor
        if ($this->jasa?->image_url) {
            return $this->jasa->image_url;
        }

        // Fall back to legacy image field
        if ($this->jasa?->image) {
            $image = $this->jasa->image;
            if (str_starts_with($image, 'http')) {
                return $image;
            }
            return asset('storage/' . $image);
        }

        return null;
    }

    /**
     * Get jasa price - prioritizes snapshot over live data
     */
    public function getJasaPriceAttribute(): ?string
    {
        return $this->jasa_price_snapshot
            ?? $this->jasa?->base_price
            ?? $this->jasa?->price
            ?? $this->jasa?->fixed_price
            ?? null;
    }

    /**
     * Get original price (from consultation) - prioritizes snapshot
     */
    public function getOriginalPriceAttribute(): ?string
    {
        return $this->original_price_snapshot
            ?? $this->original_price
            ?? null;
    }

    /**
     * Get offered price (from merchant) - prioritizes snapshot
     */
    public function getOfferedPriceAttribute(): ?string
    {
        return $this->offered_price_snapshot
            ?? $this->offered_price
            ?? null;
    }

    /**
     * Get agreed price - prioritizes snapshot
     */
    public function getAgreedPriceAttribute(): ?string
    {
        return $this->agreed_price_snapshot
            ?? $this->agreed_price
            ?? $this->price
            ?? null;
    }

    /**
     * Get service type - prioritizes snapshot over live data
     */
    public function getServiceTypeAttribute(): ?string
    {
        return $this->service_type_snapshot
            ?? ($this->attributes['service_type'] ?? null)
            ?? null;
    }

    /**
     * Get booking type - prioritizes snapshot
     */
    public function getBookingTypeAttribute(): ?string
    {
        return $this->booking_type_snapshot
            ?? ($this->attributes['booking_type'] ?? null)
            ?? null;
    }

    /**
     * Get booking date - prioritizes snapshot
     */
    public function getBookingDateAttribute(): ?string
    {
        return $this->booking_date_snapshot
            ?? ($this->attributes['booking_date'] ?? null)
            ?? null;
    }

    /**
     * Get booking time - prioritizes snapshot
     */
    public function getBookingTimeAttribute(): ?string
    {
        return $this->booking_time_snapshot
            ?? ($this->attributes['booking_time'] ?? null)
            ?? null;
    }

    /**
     * Get customer note - prioritizes snapshot
     */
    public function getCustomerNoteAttribute(): ?string
    {
        return $this->customer_note_snapshot
            ?? $this->booking_note
            ?? $this->note
            ?? null;
    }

    /**
     * Get offer note - prioritizes snapshot
     */
    public function getOfferNoteAttribute(): ?string
    {
        return $this->offer_note_snapshot
            ?? $this->offer_note
            ?? null;
    }

    /**
     * Get merchant name - prioritizes snapshot
     */
    public function getMerchantNameAttribute(): ?string
    {
        return $this->merchant_name_snapshot
            ?? $this->order?->merchant?->name
            ?? null;
    }

    /**
     * Get merchant phone - prioritizes snapshot
     */
    public function getMerchantPhoneAttribute(): ?string
    {
        return $this->merchant_phone_snapshot
            ?? $this->order?->merchant?->phone
            ?? null;
    }

    // =================================================================
    // LEGACY ACCESSORS
    // =================================================================

    public function getServiceTypeLabelAttribute(): string
    {
        return match ($this->service_type) {
            'online' => 'Online',
            'di_tempat_umkm', 'at_location' => 'Di Tempat UMKM',
            'ke_rumah_pelanggan', 'on_site' => 'Ke Rumah Pelanggan',
            default => ucfirst($this->service_type ?? '-'),
        };
    }

    public function getBookingTypeLabelAttribute(): string
    {
        // order_method stores: keranjang | booking | konsultasi
        return match ($this->order_method) {
            'keranjang' => 'Langsung Pesan',
            'booking' => 'Terjadwal',
            'konsultasi' => 'Konsultasi',
            default => ucfirst($this->order_method ?? '-'),
        };
    }

    public function getOrderMethodLabelAttribute(): string
    {
        return match ($this->order_method) {
            'keranjang' => 'Langsung Pesan',
            'booking' => 'Terjadwal',
            'konsultasi' => 'Konsultasi',
            default => ucfirst($this->order_method ?? '-'),
        };
    }

    // =================================================================
    // HELPER: Map frontend order method to internal order_method
    // =================================================================

    /**
     * Map frontend order method to internal order_method format.
     * Accepts: direct_checkout, keranjang, booking, konsultasi, consultation, scheduled, direct,
     *         langsung_pesan, memerlukan_konsultasi
     * Returns: keranjang, booking, konsultasi (frontend display format)
     */
    public static function mapToOrderMethod(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return match (strtolower($value)) {
            'direct_checkout', 'keranjang', 'langsung_pesan', 'direct' => 'keranjang',
            'booking', 'scheduled' => 'booking',
            'konsultasi', 'consultation', 'memerlukan_konsultasi' => 'konsultasi',
            default => strtolower($value),
        };
    }

    /**
     * Get order_type for orders table.
     * Returns 'jasa' for all jasa orders.
     */
    public static function getOrderTypeValue(): string
    {
        return 'jasa';
    }
}