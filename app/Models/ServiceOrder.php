<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\Carbon;

class ServiceOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'merchant_id',
        'jasa_id',
        'consultation_id',
        'service_name',
        'service_type',
        'service_image',
        'merchant_name',
        'total_price',
        'status',
        'rejection_reason',
        'rejected_at',
        'booking_date',
        'booking_time',
        'booking_note',
        'mekanisme_pemesanan',
        'completion_note',
        'customer_name',
        'customer_phone',
        'customer_address',
        'customer_latitude',
        'customer_longitude',
        'service_location_address',
        'whatsapp_redirect_url',
        'payment_method',
        'payment_status',
        'payment_reference',
        'paid_at',
        'is_reviewed',
        'review_id',
        'customer_confirmed',
        'customer_confirmed_at',
        'order_number',
    ];

    protected $casts = [
        'total_price' => 'decimal:2',
        'rejected_at' => 'datetime',
        'booking_date' => 'date',
        'booking_time' => 'datetime',
        'paid_at' => 'datetime',
        'customer_confirmed' => 'boolean',
        'customer_confirmed_at' => 'datetime',
        'is_reviewed' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ============================================================
    // STATUS CONSTANTS
    // ============================================================
    public const STATUS_MENUNGGU_KONFIRMASI = 'menunggu_konfirmasi_merchant';
    public const STATUS_DITERIMA = 'diterima';
    public const STATUS_DITOLAK = 'ditolak';
    public const STATUS_DIKERJAKAN = 'layanan_dikerjakan';
    public const STATUS_MENUNGGU_SELESAI = 'menunggu_konfirmasi_selesai';
    public const STATUS_SELESAI = 'selesai';

    public const STATUSES = [
        self::STATUS_MENUNGGU_KONFIRMASI,
        self::STATUS_DITERIMA,
        self::STATUS_DITOLAK,
        self::STATUS_DIKERJAKAN,
        self::STATUS_MENUNGGU_SELESAI,
        self::STATUS_SELESAI,
    ];

    // ============================================================
    // PAYMENT STATUS CONSTANTS
    // ============================================================
    public const PAYMENT_UNPAID = 'UNPAID';
    public const PAYMENT_WAITING = 'WAITING_CONFIRMATION';
    public const PAYMENT_PAID = 'PAID';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_UNPAID,
        self::PAYMENT_WAITING,
        self::PAYMENT_PAID,
    ];

    // ============================================================
    // RELATIONSHIPS
    // ============================================================

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function jasa(): BelongsTo
    {
        return $this->belongsTo(Jasa::class);
    }

    /**
     * Consultation that led to this order (if created from consultation)
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(ServiceConsultation::class, 'consultation_id');
    }

    /**
     * Review for this order (if customer submitted one)
     */
    public function review(): HasOne
    {
        return $this->hasOne(Rating::class, 'service_order_id');
    }

    /**
     * Work completion evidences uploaded by merchant
     */
    public function completionEvidences(): HasMany
    {
        return $this->hasMany(ServiceCompletionEvidence::class, 'service_order_id');
    }

    public function jasaOrderItems(): HasMany
    {
        return $this->hasMany(JasaOrderItem::class, 'service_order_id');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_SELESAI);
    }

    public function scopeCanBeReviewed($query)
    {
        return $query->where('status', self::STATUS_SELESAI)
            ->where('is_reviewed', false);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_MENUNGGU_KONFIRMASI);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_SELESAI, self::STATUS_DITOLAK]);
    }

    // ============================================================
    // STATUS TRANSITION RULES
    // ============================================================

    /**
     * Get valid next statuses for the current status.
     */
    public static function getValidNextStatuses(string $currentStatus): array
    {
        return match ($currentStatus) {
            self::STATUS_MENUNGGU_KONFIRMASI => [self::STATUS_DITERIMA, self::STATUS_DITOLAK],
            self::STATUS_DITERIMA => [self::STATUS_DIKERJAKAN],
            self::STATUS_DIKERJAKAN => [self::STATUS_MENUNGGU_SELESAI],
            self::STATUS_MENUNGGU_SELESAI => [self::STATUS_SELESAI],
            // Terminal states
            self::STATUS_SELESAI => [],
            self::STATUS_DITOLAK => [],
            default => [],
        };
    }

    /**
     * Check if transition to a new status is allowed.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        $validNext = self::getValidNextStatuses($this->status);
        return in_array($newStatus, $validNext);
    }

    /**
     * Check if order is in a terminal (final) state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_SELESAI, self::STATUS_DITOLAK]);
    }

    /**
     * Check if merchant actions are allowed on this order.
     */
    public function merchantCanAct(): bool
    {
        // Merchant can act on orders that are not yet SELESAI or DITOLAK
        return !in_array($this->status, [self::STATUS_SELESAI, self::STATUS_DITOLAK]);
    }

    // ============================================================
    // ACCESSORS
    // ============================================================

    public function getStatusLabelAttribute(): string
    {
        return self::getStatusLabelStatic($this->status);
    }

    public static function getStatusLabelStatic(string $status): string
    {
        return match ($status) {
            self::STATUS_MENUNGGU_KONFIRMASI => 'Menunggu Konfirmasi',
            self::STATUS_DITERIMA => 'Diterima',
            self::STATUS_DITOLAK => 'Ditolak',
            self::STATUS_DIKERJAKAN => 'Sedang Dikerjakan',
            self::STATUS_MENUNGGU_SELESAI => 'Menunggu Konfirmasi Selesai',
            self::STATUS_SELESAI => 'Selesai',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public function getCanBeReviewedAttribute(): bool
    {
        return $this->status === self::STATUS_SELESAI && !$this->is_reviewed;
    }

    public function getHasReviewAttribute(): bool
    {
        return $this->is_reviewed && $this->review_id !== null;
    }

    public function getFormattedOrderNumberAttribute(): string
    {
        return $this->order_number ?? ('SO-' . str_pad($this->id, 6, '0', STR_PAD_LEFT));
    }

    public function getBookingDateTimeDisplayAttribute(): ?string
    {
        if (!$this->booking_date) {
            return null;
        }

        $date = Carbon::parse($this->booking_date)->locale('id_ID');
        $timePart = $this->booking_time
            ? ', ' . Carbon::parse($this->booking_time)->format('H:i') . ' WIB'
            : '';

        return $date->format('d F Y') . $timePart;
    }

    public function getFormattedPriceAttribute(): string
    {
        return 'Rp ' . number_format((float) $this->total_price, 0, ',', '.');
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return match ($this->payment_status) {
            self::PAYMENT_UNPAID => 'Belum Bayar',
            self::PAYMENT_WAITING => 'Menunggu Konfirmasi',
            self::PAYMENT_PAID => 'Sudah Bayar',
            default => ucfirst($this->payment_status ?? 'Unknown'),
        };
    }

    public function getBookingDisplayAttribute(): string
    {
        $display = $this->bookingDateTimeDisplay;
        return $display ?? 'Tidak menggunakan jadwal';
    }

    // ============================================================
    // HELPER METHODS
    // ============================================================

    /**
     * Update order status with transition validation.
     *
     * @throws \InvalidArgumentException if transition is not allowed
     */
    public function updateStatus(string $newStatus, array $options = []): bool
    {
        if (!$this->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Transisi status tidak valid: tidak dapat berubah dari '{$this->status}' ke '{$newStatus}'"
            );
        }

        $this->status = $newStatus;

        if ($newStatus === self::STATUS_DITOLAK) {
            $this->rejection_reason = $options['rejection_reason'] ?? $options['rejection_note'] ?? null;
            $this->rejected_at = now();
        }

        if (($options['mark_paid'] ?? false) && $newStatus === self::STATUS_SELESAI) {
            $this->payment_status = self::PAYMENT_PAID;
            $this->paid_at = now();
        }

        $this->save();
        return true;
    }

    /**
     * Mark order as having a review submitted.
     */
    public function markAsReviewed(int $reviewId): void
    {
        $this->update([
            'is_reviewed' => true,
            'review_id' => $reviewId,
        ]);
    }

    /**
     * Mark order as completed by customer (after confirming work evidence).
     */
    public function confirmCompleted(): bool
    {
        if ($this->status !== self::STATUS_MENUNGGU_SELESAI) {
            return false;
        }

        $this->customer_confirmed = true;
        $this->customer_confirmed_at = now();
        $this->status = self::STATUS_SELESAI;
        $this->save();

        return true;
    }

    /**
     * Generate order number on creation.
     */
    public static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $datePart = now()->format('Ymd');
                $randomPart = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $order->order_number = "SO-{$datePart}-{$randomPart}";
            }

            // Set default payment status
            if (empty($order->payment_status)) {
                $order->payment_status = self::PAYMENT_UNPAID;
            }
        });
    }
}
