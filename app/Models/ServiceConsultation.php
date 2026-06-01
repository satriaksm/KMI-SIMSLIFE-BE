<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceConsultation extends Model
{
    use HasFactory;

    protected $fillable = [
        'jasa_id',
        'customer_id',
        'merchant_id',
        'service_name',
        'original_price',
        'customer_description',
        'customer_budget',
        'customer_deadline',
        'customer_note',
        'merchant_response',
        'merchant_offered_price',
        'merchant_note',
        'offer_status',
        'negotiated_price',
        'negotiation_notes',
        'agreed_deadline',
        'customer_accepted',
        'customer_accepted_at',
        'status',
        'responded_at',
        'closed_at',
        'service_order_id',
        // Booking proposal fields
        'proposed_date',
        'proposed_time',
        'proposed_notes',
    ];

    protected $casts = [
        'original_price' => 'decimal:2',
        'customer_budget' => 'decimal:2',
        'merchant_offered_price' => 'decimal:2',
        'negotiated_price' => 'decimal:2',
        'customer_deadline' => 'date',
        'agreed_deadline' => 'date',
        'proposed_date' => 'date',
        'proposed_time' => 'datetime',
        'customer_accepted' => 'boolean',
        'customer_accepted_at' => 'datetime',
        'responded_at' => 'datetime',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ============================================================
    // STATUS CONSTANTS
    // ============================================================
    public const STATUS_PENDING = 'pending';
    public const STATUS_DAPAT_DIKERJAKAN = 'dapat_dikerjakan';       // Merchant says can do it
    public const STATUS_PENYESUAIAN = 'perlu_penyesuaian';           // Merchant needs adjustments
    public const STATUS_DITOLAK = 'ditolak';                        // Merchant cannot do it
    public const STATUS_ACCEPTED = 'accepted';                      // Customer accepted the offer
    public const STATUS_CLOSED = 'closed';                          // Consultation closed
    public const STATUS_OFFER_ACCEPTED = 'offer_accepted';          // Customer accepted merchant offer (legacy)
    public const STATUS_OFFER_REJECTED = 'penawaran_ditolak';      // Customer rejected merchant offer

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DAPAT_DIKERJAKAN,
        self::STATUS_PENYESUAIAN,
        self::STATUS_DITOLAK,
        self::STATUS_ACCEPTED,
        self::STATUS_CLOSED,
        self::STATUS_OFFER_ACCEPTED,
        self::STATUS_OFFER_REJECTED,
    ];

    // ============================================================
    // MERCHANT RESPONSE OPTIONS
    // ============================================================
    public const RESPONSE_BISA = 'bisa_dikerjakan';
    public const RESPONSE_PENYESUAIAN = 'perlu_penyesuaian';
    public const RESPONSE_TIDAK = 'tidak_bisa_dikerjakan';

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

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    public function jasaOrderItems(): HasMany
    {
        return $this->hasMany(JasaOrderItem::class, 'service_consultation_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ServiceConsultationMedia::class, 'service_consultation_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ServiceConsultationNote::class, 'service_consultation_id');
    }

    /**
     * Chat messages (new system with media support)
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConsultationMessage::class, 'service_consultation_id')->orderBy('created_at');
    }

    // ============================================================
    // SCOPES
    // ============================================================

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [
            self::STATUS_DITOLAK,
            self::STATUS_CLOSED,
            self::STATUS_ACCEPTED,
            self::STATUS_OFFER_ACCEPTED,
            self::STATUS_OFFER_REJECTED,
        ]);
    }

    public function scopeForCustomer($query, int $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    // ============================================================
    // STATUS LABELS
    // ============================================================

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Menunggu Tanggapan Merchant',
            self::STATUS_DAPAT_DIKERJAKAN => 'Dapat Dikerjakan',
            self::STATUS_PENYESUAIAN => 'Perlu Penyesuaian',
            self::STATUS_DITOLAK => 'Tidak Dapat Dikerjakan',
            self::STATUS_ACCEPTED => 'Disepakati',
            self::STATUS_CLOSED => 'Ditutup',
            self::STATUS_OFFER_ACCEPTED => 'Penawaran Disetujui',
            self::STATUS_OFFER_REJECTED => 'Penawaran Ditolak',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    /**
     * Status group for simplified UI filters.
     * Values: menunggu, negosiasi, selesai
     */
    public function getStatusGroupAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'menunggu',
            self::STATUS_DAPAT_DIKERJAKAN, self::STATUS_PENYESUAIAN => 'negosiasi',
            self::STATUS_ACCEPTED, self::STATUS_DITOLAK, self::STATUS_CLOSED, self::STATUS_OFFER_ACCEPTED, self::STATUS_OFFER_REJECTED => 'selesai',
            default => 'menunggu',
        };
    }

    public function getMerchantResponseLabelAttribute(): string
    {
        return match ($this->merchant_response) {
            self::RESPONSE_BISA => 'Bisa Dikerjakan',
            self::RESPONSE_PENYESUAIAN => 'Bisa Dikerjakan Dengan Penyesuaian',
            self::RESPONSE_TIDAK => 'Tidak Bisa Dikerjakan',
            default => $this->merchant_response ?? '-',
        };
    }

    // ============================================================
    // HELPER METHODS
    // ============================================================

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_DAPAT_DIKERJAKAN,
            self::STATUS_PENYESUAIAN,
        ]);
    }

    public function canCustomerAccept(): bool
    {
        return in_array($this->status, [
            self::STATUS_DAPAT_DIKERJAKAN,
            self::STATUS_PENYESUAIAN,
        ]) && !$this->customer_accepted;
    }

    /**
     * Check if consultation can receive merchant response.
     */
    public function canRespond(): bool
    {
        // Can respond when consultation is active and not yet accepted/closed/rejected
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_DAPAT_DIKERJAKAN,
            self::STATUS_PENYESUAIAN,
        ]) && !$this->customer_accepted;
    }

    /**
     * Merchant responds to the consultation request.
     * Can respond at multiple statuses (pending, dapat_dikerjakan, perlu_penyesuaian)
     */
    public function respond(string $response, ?float $offeredPrice = null, ?string $note = null): bool
    {
        if (!$this->canRespond()) {
            return false;
        }

        $this->merchant_response = $response;
        $this->merchant_offered_price = $offeredPrice;
        $this->merchant_note = $note;
        $this->responded_at = now();

        switch ($response) {
            case self::RESPONSE_BISA:
                $this->status = self::STATUS_DAPAT_DIKERJAKAN;
                break;
            case self::RESPONSE_PENYESUAIAN:
                $this->status = self::STATUS_PENYESUAIAN;
                break;
            case self::RESPONSE_TIDAK:
                $this->status = self::STATUS_DITOLAK;
                $this->closed_at = now();
                break;
        }

        return $this->save();
    }

    /**
     * Computed initial price (original service price).
     */
    public function getInitialPriceAttribute(): ?float
    {
        return $this->original_price ? floatval($this->original_price) : null;
    }

    /**
     * Computed final price (agreed/negotiated price).
     */
    public function getFinalPriceAttribute(): ?float
    {
        if ($this->status === self::STATUS_ACCEPTED && $this->negotiated_price) {
            return floatval($this->negotiated_price);
        }
        return null;
    }

    /**
     * Customer accepts the offer and a service order is created.
     */
    public function accept(int $serviceOrderId, ?float $negotiatedPrice = null): bool
    {
        if (!$this->canCustomerAccept()) {
            return false;
        }

        $this->customer_accepted = true;
        $this->customer_accepted_at = now();
        $this->status = self::STATUS_ACCEPTED;
        $this->service_order_id = $serviceOrderId;
        $this->negotiated_price = $negotiatedPrice ?? $this->merchant_offered_price;

        return $this->save();
    }

    /**
     * Close the consultation without agreement.
     */
    public function close(?string $reason = null): bool
    {
        if ($this->status === self::STATUS_ACCEPTED || $this->status === self::STATUS_CLOSED) {
            return false;
        }

        $this->status = self::STATUS_CLOSED;
        $this->closed_at = now();
        if ($reason) {
            $this->negotiation_notes = trim(($this->negotiation_notes ?? '') . "\n[Penutupan] " . $reason);
        }

        return $this->save();
    }

    /**
     * Add a note/message to the consultation.
     */
    public function addNote(int $senderId, string $senderType, string $noteText): ServiceConsultationNote
    {
        return $this->notes()->create([
            'sender_id' => $senderId,
            'sender_type' => $senderType,
            'note' => $noteText,
        ]);
    }
}