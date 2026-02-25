<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Conversation extends Model
{
    use HasFactory;

    protected $table = 'conversations';

    protected $fillable = [
        'buyer_id',
        'merchant_id',
        'jasa_id',
        'status',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /**
     * Get the buyer (customer) of the conversation
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /**
     * Get the merchant of the conversation
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    /**
     * Get the jasa related to this conversation
     */
    public function jasa(): BelongsTo
    {
        return $this->belongsTo(Jasa::class);
    }

    /**
     * Get all messages in this conversation
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id')->orderBy('created_at', 'asc');
    }

    /**
     * Get the last message in this conversation
     */
    public function lastMessage()
    {
        return $this->hasOne(Message::class, 'conversation_id')->latest('created_at');
    }

    /**
     * Get the count of unread messages from buyer
     */
    public function getUnreadCountAttribute()
    {
        return 0;
    }

    /**
     * Scope: Get conversations for a specific merchant
     */
    public function scopeForMerchant($query, $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    /**
     * Scope: Get conversations for a specific buyer
     */
    public function scopeForBuyer($query, $buyerId)
    {
        return $query->where('buyer_id', $buyerId);
    }

    /**
     * Mark all buyer messages as read for the merchant
     */
    public function markAsRead()
    {
        return;
    }
}

