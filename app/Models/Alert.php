<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    use HasFactory;

    protected $fillable = [
        'alertable_type',
        'alertable_id',
        'alert_type',
        'priority',
        'status',
        'message',
        'recommended_actions',
        'metadata',
        'assigned_to',
        'assigned_at',
        'resolved_at',
    ];

    protected $guarded = [
        'id',
    ];

    protected $casts = [
        'recommended_actions' => 'array',
        'metadata' => 'array',
        'assigned_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Alert target (polymorphic)
     * Bisa User, Merchant, Product, dll
     */
    public function alertable()
    {
        return $this->morphTo();
    }

    /**
     * Admin yang di-assign
     */
    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Filter pending alerts
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Filter by priority
     */
    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * High priority alerts
     */
    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', ['high', 'critical']);
    }

    /**
     * Critical alerts only
     */
    public function scopeCritical($query)
    {
        return $query->where('priority', 'critical');
    }

    /**
     * Filter by alert type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('alert_type', $type);
    }

    /**
     * Assigned to specific admin
     */
    public function scopeAssignedTo($query, $adminId)
    {
        return $query->where('assigned_to', $adminId);
    }

    /**
     * Unassigned alerts
     */
    public function scopeUnassigned($query)
    {
        return $query->whereNull('assigned_to');
    }

    /**
     * Order by priority (critical first)
     */
    public function scopeOrderByPriority($query)
    {
        return $query->orderByRaw("FIELD(priority, 'critical', 'high', 'medium', 'low')");
    }

    /**
     * Alerts older than X days
     */
    public function scopeOlderThan($query, $days)
    {
        return $query->where('created_at', '<', now()->subDays($days));
    }

    /**
     * SLA breached (pending > 48 hours for high priority)
     */
    public function scopeSlaBreached($query)
    {
        return $query->where('status', 'pending')
            ->whereIn('priority', ['high', 'critical'])
            ->where('created_at', '<', now()->subHours(48));
    }

    // ============================================
    // ACCESSORS & MUTATORS
    // ============================================

    /**
     * Get priority badge class for UI
     */
    public function getPriorityBadgeAttribute()
    {
        return match ($this->priority) {
            'critical' => 'bg-red-600 text-white',
            'high' => 'bg-orange-500 text-white',
            'medium' => 'bg-yellow-500 text-black',
            'low' => 'bg-gray-400 text-white',
            default => 'bg-gray-300 text-black',
        };
    }

    /**
     * Get status badge class
     */
    public function getStatusBadgeAttribute()
    {
        return match ($this->status) {
            'pending' => 'bg-yellow-500',
            'in_progress' => 'bg-blue-500',
            'resolved' => 'bg-green-500',
            'dismissed' => 'bg-gray-500',
            default => 'bg-gray-300',
        };
    }

    /**
     * Get formatted alert type
     */
    public function getAlertTypeLabelAttribute()
    {
        return ucwords(str_replace('_', ' ', $this->alert_type));
    }

    /**
     * Check if alert is overdue (SLA breach)
     */
    public function getIsOverdueAttribute()
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $slaHours = match ($this->priority) {
            'critical' => 24,
            'high' => 48,
            'medium' => 72,
            'low' => 168, // 7 days
            default => 168,
        };

        return $this->created_at->diffInHours(now()) > $slaHours;
    }

    /**
     * Get time remaining before SLA breach
     */
    public function getTimeRemainingAttribute()
    {
        if ($this->status !== 'pending') {
            return null;
        }

        $slaHours = match ($this->priority) {
            'critical' => 24,
            'high' => 48,
            'medium' => 72,
            'low' => 168,
            default => 168,
        };

        $elapsedHours = $this->created_at->diffInHours(now());
        $remainingHours = $slaHours - $elapsedHours;

        if ($remainingHours <= 0) {
            return 'Overdue';
        }

        return $remainingHours . ' hours';
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    /**
     * Assign alert to admin
     */
    public function assignTo($adminId)
    {
        $this->update([
            'assigned_to' => $adminId,
            'assigned_at' => now(),
            'status' => 'in_progress',
        ]);
    }

    /**
     * Mark alert as resolved
     */
    public function resolve()
    {
        $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);
    }

    /**
     * Dismiss alert
     */
    public function dismiss()
    {
        $this->update([
            'status' => 'dismissed',
            'resolved_at' => now(),
        ]);
    }

    /**
     * Escalate priority
     */
    public function escalate()
    {
        $newPriority = match ($this->priority) {
            'low' => 'medium',
            'medium' => 'high',
            'high' => 'critical',
            'critical' => 'critical', // already max
            default => 'high',
        };

        $this->update(['priority' => $newPriority]);
    }
}
