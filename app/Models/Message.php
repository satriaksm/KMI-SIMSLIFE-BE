<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'sender_role',
        'type',
        'body',
        'jasa_id',
        'offer_price',
        'offer_status',
    ];

    protected $casts = [
    ];

    /**
     * Get the conversation this message belongs to
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * Get the sender user
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Scope: Get unread messages
     */
    public function scopeUnread($query)
    {
        return $query->whereRaw('1 = 0');
    }

    /**
     * Scope: Get messages from a specific sender role
     */
    public function scopeFromRole($query, $role)
    {
        return $query->where('sender_role', $role);
    }

    /**
     * Scope: Get only message type (not system messages)
     */
    public function scopeMessages($query)
    {
        return $query->where('type', 'message');
    }
}