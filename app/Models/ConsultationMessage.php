<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsultationMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_consultation_id',
        'sender_id',
        'sender_type',
        'message',
        'proposed_price',
    ];

    protected $casts = [
        'proposed_price' => 'decimal:2',
    ];

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

    public function media(): HasMany
    {
        return $this->hasMany(ConsultationMessageMedia::class, 'consultation_message_id')->orderBy('display_order');
    }

    // ============================================================
    // HELPERS
    // ============================================================

    public function isFromCustomer(): bool
    {
        return $this->sender_type === 'customer';
    }

    public function isFromMerchant(): bool
    {
        return $this->sender_type === 'merchant';
    }

    public function hasMedia(): bool
    {
        return $this->media->isNotEmpty();
    }

    public function hasProposedPrice(): bool
    {
        return $this->proposed_price !== null;
    }
}
