<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_id',
        'action_type',
        'target_type',
        'target_id',
        'reason',
        'metadata',
        'status_before',
        'status_after',
    ];

    protected $guarded = [
        'id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * Admin yang melakukan aksi
     */
    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Target aksi (polymorphic)
     * Bisa User, Merchant, Product, CommunityPost, dll
     */
    public function target()
    {
        return $this->morphTo();
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Filter by action type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('action_type', $type);
    }

    /**
     * Filter by admin
     */
    public function scopeByAdmin($query, $adminId)
    {
        return $query->where('admin_id', $adminId);
    }

    /**
     * Filter by target
     */
    public function scopeForTarget($query, $targetType, $targetId)
    {
        return $query->where('target_type', $targetType)
            ->where('target_id', $targetId);
    }

    /**
     * Get recent actions
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Status changes only
     */
    public function scopeStatusChanges($query)
    {
        return $query->where('action_type', 'status_change');
    }

    // ============================================
    // ACCESSORS & MUTATORS
    // ============================================

    /**
     * Get formatted action type
     */
    public function getActionTypeLabelAttribute()
    {
        return ucwords(str_replace('_', ' ', $this->action_type));
    }

    /**
     * Get target name
     */
    public function getTargetNameAttribute()
    {
        if (!$this->target) {
            return 'Unknown';
        }

        return $this->target->name ?? $this->target->title ?? "#{$this->target_id}";
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    /**
     * Check if action is critical (suspend, ban, etc)
     */
    public function isCriticalAction()
    {
        return in_array($this->action_type, [
            'suspend_user',
            'bulk_update',
            'manual_override',
        ]);
    }

    /**
     * Get action summary for logs
     */
    public function getSummary()
    {
        return sprintf(
            '%s: %s → %s by %s',
            $this->action_type_label,
            $this->status_before ?? 'N/A',
            $this->status_after ?? 'N/A',
            $this->admin->name ?? 'System'
        );
    }
}
