<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'reportable_type',
        'reportable_id',
        'report_reason_id',
        'report_comment',
        'status',
        'reviewed_by',
        'admin_note',
        'reviewed_at',
        // ✅ NEW: Forwarding fields
        'forwarded_to',
        'forwarded_by',
        'forwarded_at',
        'forward_message',
        // ✅ NEW: Action tracking
        'action_taken',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'forwarded_at' => 'datetime',
    ];

    // ✅ Relationships
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(ReportReason::class, 'report_reason_id');
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    // ✅ NEW: Forwarding relationships
    public function forwardedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_to');
    }

    public function forwardedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_by');
    }

    // ✅ Scopes
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

    public function scopeDismissed($query)
    {
        return $query->where('status', 'dismissed');
    }

    // ✅ NEW: Sanggahan/appeals
    public function appeals(): HasMany
    {
        return $this->hasMany(ReportAppeal::class, 'content_report_id');
    }

    public function latestAppeal()
    {
        return $this->hasOne(ReportAppeal::class, 'content_report_id')->latest();
    }

    // ✅ Helper: Check if "Lainnya" reason requires comment
    public function requiresComment(): bool
    {
        return $this->reason &&
               strtolower($this->reason->reason_title) === 'lainnya' &&
               empty($this->report_comment);
    }

    // ✅ Helper: Get target user of this report
    public function getTargetUser(): ?User
    {
        $reportable = $this->reportable;
        if (!$reportable) return null;

        if ($reportable instanceof User) return $reportable;
        if ($reportable instanceof Merchant) return $reportable->user;
        if ($reportable instanceof Product) return $reportable->merchant?->user;
        if ($reportable instanceof Jasa) return $reportable->merchant?->user;
        if (isset($reportable->user_id)) return User::find($reportable->user_id);

        return null;
    }

    // ✅ Helper: Get target name/title
    public function getTargetName(): string
    {
        $reportable = $this->reportable;
        if (!$reportable) return 'Konten';

        if ($reportable instanceof User) return $reportable->name;
        if ($reportable instanceof Merchant) return $reportable->name ?? 'Merchant';
        if ($reportable instanceof Product) return $reportable->name;
        if ($reportable instanceof Jasa) return $reportable->name ?? 'Jasa';
        
        // For posts or comments
        if (isset($reportable->content)) return \Illuminate\Support\Str::limit(strip_tags($reportable->content), 30);
        if (isset($reportable->title)) return $reportable->title;

        return 'Konten';
    }
}
