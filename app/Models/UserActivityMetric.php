<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserActivityMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'posts_30d',
        'comments_30d',
        'orders_30d',
        'total_activity_30d',
        'reports_validated_30d',
        'reports_total',
        'last_login_at',
        'last_login_days',
        'activity_score',
        'pattern_spam_detected',
    ];

    protected $casts = [
        'posts_30d' => 'integer',
        'comments_30d' => 'integer',
        'orders_30d' => 'integer',
        'total_activity_30d' => 'integer',
        'reports_validated_30d' => 'integer',
        'reports_total' => 'integer',
        'last_login_days' => 'integer',
        'activity_score' => 'decimal:2',
        'pattern_spam_detected' => 'boolean',
        'last_login_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * User yang memiliki metric ini
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * High activity users
     */
    public function scopeHighActivity($query, $threshold = 10)
    {
        return $query->where('total_activity_30d', '>=', $threshold);
    }

    /**
     * Low activity users
     */
    public function scopeLowActivity($query, $threshold = 3)
    {
        return $query->where('total_activity_30d', '<', $threshold);
    }

    /**
     * Users with reports
     */
    public function scopeHasReports($query, $minReports = 1)
    {
        return $query->where('reports_validated_30d', '>=', $minReports);
    }

    /**
     * Watchlist threshold (5+ reports)
     */
    public function scopeWatchlistThreshold($query)
    {
        return $query->where('reports_validated_30d', '>=', 5)
                     ->where('reports_validated_30d', '<', 10);
    }

    /**
     * Suspended threshold (10+ reports)
     */
    public function scopeSuspendedThreshold($query)
    {
        return $query->where('reports_validated_30d', '>=', 10);
    }

    /**
     * Dormant users (no login > 30 days)
     */
    public function scopeDormant($query)
    {
        return $query->where('last_login_days', '>', 30);
    }

    /**
     * Active users (logged in recently)
     */
    public function scopeActive($query, $days = 7)
    {
        return $query->where('last_login_days', '<=', $days);
    }

    /**
     * Spam pattern detected
     */
    public function scopeSpamDetected($query)
    {
        return $query->where('pattern_spam_detected', true);
    }

    // ============================================
    // ACCESSORS & MUTATORS
    // ============================================

    /**
     * Get activity level label
     */
    public function getActivityLevelAttribute()
    {
        if ($this->total_activity_30d >= 20) {
            return 'Very Active';
        } elseif ($this->total_activity_30d >= 10) {
            return 'Active';
        } elseif ($this->total_activity_30d >= 5) {
            return 'Moderate';
        } elseif ($this->total_activity_30d >= 1) {
            return 'Low';
        } else {
            return 'Inactive';
        }
    }

    /**
     * Get report risk level
     */
    public function getReportRiskLevelAttribute()
    {
        if ($this->reports_validated_30d >= 10) {
            return 'Critical';
        } elseif ($this->reports_validated_30d >= 5) {
            return 'High';
        } elseif ($this->reports_validated_30d >= 3) {
            return 'Medium';
        } elseif ($this->reports_validated_30d >= 1) {
            return 'Low';
        } else {
            return 'None';
        }
    }

    /**
     * Get login status
     */
    public function getLoginStatusAttribute()
    {
        if ($this->last_login_days === 0) {
            return 'Today';
        } elseif ($this->last_login_days <= 7) {
            return 'This week';
        } elseif ($this->last_login_days <= 30) {
            return 'This month';
        } else {
            return 'Dormant';
        }
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    /**
     * Update activity counts from database
     */
    public function recalculate()
    {
        $user = $this->user;
        $startDate = now()->subDays(30);

        $this->update([
            'posts_30d' => $user->communityPosts()->where('created_at', '>=', $startDate)->count(),
            'comments_30d' => $user->postComments()->where('created_at', '>=', $startDate)->count(),
            'orders_30d' => $user->orders()->where('created_at', '>=', $startDate)->count(),
        ]);

        $this->update([
            'total_activity_30d' => $this->posts_30d + $this->comments_30d + $this->orders_30d,
        ]);

        $this->calculateScore();
    }

    /**
     * Calculate activity score
     */
    public function calculateScore()
    {
        // Weighted scoring: posts=3, comments=1, orders=5
        $score = ($this->posts_30d * 3) + ($this->comments_30d * 1) + ($this->orders_30d * 5);
        
        // Penalty for reports
        $score -= ($this->reports_validated_30d * 10);

        // Minimum score = 0
        $score = max(0, $score);

        $this->update(['activity_score' => $score]);
    }

    /**
     * Detect spam pattern
     */
    public function detectSpamPattern()
    {
        // Spam criteria: too many posts in short time, or high report ratio
        $spamDetected = false;

        // High post frequency with low comments (bot-like)
        if ($this->posts_30d > 50 && $this->comments_30d < 5) {
            $spamDetected = true;
        }

        // High report ratio
        if ($this->reports_validated_30d >= 3 && $this->total_activity_30d > 0) {
            $reportRatio = $this->reports_validated_30d / $this->total_activity_30d;
            if ($reportRatio > 0.3) { // 30% of activities reported
                $spamDetected = true;
            }
        }

        $this->update(['pattern_spam_detected' => $spamDetected]);

        return $spamDetected;
    }

    /**
     * Update last login
     */
    public function updateLastLogin()
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_days' => 0,
        ]);
    }

    /**
     * Calculate days since last login
     */
    public function updateLastLoginDays()
    {
        if ($this->last_login_at) {
            $this->update([
                'last_login_days' => now()->diffInDays($this->last_login_at),
            ]);
        }
    }
}