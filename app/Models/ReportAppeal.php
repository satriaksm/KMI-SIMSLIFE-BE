<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportAppeal extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_report_id',
        'user_id',
        'appeal_text',
        'status',
        'admin_response',
        'responded_by',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(ContentReport::class, 'content_report_id');
    }

    public function appellant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isReviewed(): bool
    {
        return in_array($this->status, ['reviewed', 'accepted', 'rejected']);
    }
}
