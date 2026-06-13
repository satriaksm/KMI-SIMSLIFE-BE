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
        'service_order_id',
        'service_consultation_id',
        'quantity',
        'price',
        'subtotal',
        'booking_date',
        'booking_time',
        'service_type',
        'service_type_booking', // Legacy: keranjang/booking/konsultasi
        'order_method', // NEW: direct/scheduled/consultation
        'note',
        // Service-specific fields
        'booking_note',
        'service_location_address',
        'customer_latitude',
        'customer_longitude',
        'whatsapp_redirect_url',
        'completion_note',
        'customer_confirmed',
        'customer_confirmed_at',
        'is_reviewed',
        'review_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'booking_date' => 'date',
        'customer_latitude' => 'decimal:8',
        'customer_longitude' => 'decimal:8',
        'customer_confirmed' => 'boolean',
        'customer_confirmed_at' => 'datetime',
        'is_reviewed' => 'boolean',
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

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
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
     * Completion evidences linked to this order item
     */
    public function completionEvidences(): HasMany
    {
        return $this->hasMany(ServiceCompletionEvidence::class, 'service_order_id', 'service_order_id');
    }

    /**
     * Review for this order item
     */
    public function review(): HasOne
    {
        return $this->hasOne(Rating::class, 'jasa_order_item_id');
    }

    // Accessors
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
        // Use order_method first, then fallback to service_type_booking
        return $this->order_method
            ?? match ($this->service_type_booking) {
                'keranjang' => 'Langsung Pesan',
                'booking' => 'Terjadwal',
                'konsultasi' => 'Konsultasi',
                default => ucfirst($this->service_type_booking ?? '-'),
            };
    }

    public function getOrderMethodLabelAttribute(): string
    {
        return match ($this->order_method) {
            'direct' => 'Langsung Pesan',
            'scheduled' => 'Terjadwal',
            'consultation' => 'Konsultasi',
            default => ucfirst($this->order_method ?? '-'),
        };
    }

    // =================================================================
    // HELPER: Map frontend order method to internal order_method
    // =================================================================

    /**
     * Map frontend order method to internal order_method format.
     * Accepts: direct_checkout, keranjang, booking, konsultasi, consultation, scheduled, direct
     * Returns: direct, scheduled, consultation
     */
    public static function mapToOrderMethod(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return match (strtolower($value)) {
            'direct_checkout', 'keranjang', 'langsung_pesan', 'direct' => 'direct',
            'booking', 'scheduled' => 'scheduled',
            'konsultasi', 'consultation' => 'consultation',
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
