<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceConsultationNote extends Model
{
    use HasFactory;

    protected $table = 'service_consultation_notes';

    protected $fillable = [
        'service_consultation_id',
        'sender_id',
        'sender_type',
        'note',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ============================================================
    // SENDER TYPE CONSTANTS
    // ============================================================
    public const SENDER_CUSTOMER = 'customer';
    public const SENDER_MERCHANT = 'merchant';
    public const SENDER_SYSTEM = 'system';

    // ============================================================
    // RELATIONSHIPS
    // ============================================================

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(ServiceConsultation::class, 'service_consultation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    // ============================================================
    // ACCESSORS
    // ============================================================

    public function getIsCustomerAttribute(): bool
    {
        return $this->sender_type === self::SENDER_CUSTOMER;
    }

    public function getIsMerchantAttribute(): bool
    {
        return $this->sender_type === self::SENDER_MERCHANT;
    }

    public function getIsSystemAttribute(): bool
    {
        return $this->sender_type === self::SENDER_SYSTEM;
    }
}