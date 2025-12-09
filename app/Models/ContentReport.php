<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContentReport extends Model
{
    protected $fillable = [
        'user_id',
        'report_reason_id',
        'reportable_type',
        'reportable_id',
        'report_comment',
        'status',
        'reviewed_by',
        'admin_note',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    // User who reported
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Admin who reviewed
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // Report reason
    public function reason(): BelongsTo
    {
        return $this->belongsTo(ReportReason::class, 'report_reason_id');
    }

    // Polymorphic relation to reported content
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInReview($query)
    {
        return $query->where('status', 'in_review');
    }

    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }
}