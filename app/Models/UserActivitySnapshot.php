<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class UserActivitySnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'period',
        'posts_count',
        'comments_count',
        'orders_count',
        'total_activity',
        'activity_score',
        'population_avg_activity',
    ];

    protected $casts = [
        'posts_count' => 'integer',
        'comments_count' => 'integer',
        'orders_count' => 'integer',
        'total_activity' => 'integer',
        'activity_score' => 'decimal:2',
        'population_avg_activity' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ============================================
    // RELATIONSHIPS
    // ============================================

    /**
     * User yang memiliki snapshot ini
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ============================================
    // SCOPES
    // ============================================

    /**
     * Filter by period
     */
    public function scopeForPeriod($query, $period)
    {
        return $query->where('period', $period);
    }

    /**
     * Get current month snapshot
     */
    public function scopeCurrentMonth($query)
    {
        return $query->where('period', now()->format('Y-m'));
    }

    /**
     * Get previous month snapshot
     */
    public function scopePreviousMonth($query)
    {
        return $query->where('period', now()->subMonth()->format('Y-m'));
    }

    /**
     * Get specific user snapshots
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Order by period descending (newest first)
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('period', 'desc');
    }

    /**
     * Declining activity (below avg)
     */
    public function scopeDeclining($query)
    {
        return $query->where(DB::raw('total_activity'), '<', DB::raw('population_avg_activity * 0.8'));
    }

    /**
     * High performers (above avg)
     */
    public function scopeHighPerformers($query)
    {
        return $query->where(DB::raw('total_activity'), '>=', DB::raw('population_avg_activity * 1.2'));
    }

    // ============================================
    // ACCESSORS & MUTATORS
    // ============================================

    /**
     * Get formatted period (e.g., "December 2025")
     */
    public function getPeriodFormattedAttribute()
    {
        return Carbon::createFromFormat('Y-m', $this->period)->format('F Y');
    }

    /**
     * Get period as Carbon instance
     */
    public function getPeriodDateAttribute()
    {
        return Carbon::createFromFormat('Y-m', $this->period);
    }

    /**
     * Check if declining compared to population avg
     */
    public function getIsDecliningAttribute()
    {
        if ($this->population_avg_activity === 0) {
            return false;
        }

        return $this->total_activity < ($this->population_avg_activity * 0.8);
    }

    /**
     * Get performance level
     */
    public function getPerformanceLevelAttribute()
    {
        if ($this->population_avg_activity === 0) {
            return 'Unknown';
        }

        $ratio = $this->total_activity / $this->population_avg_activity;

        if ($ratio >= 1.5) {
            return 'Excellent';
        } elseif ($ratio >= 1.2) {
            return 'Above Average';
        } elseif ($ratio >= 0.8) {
            return 'Average';
        } elseif ($ratio >= 0.5) {
            return 'Below Average';
        } else {
            return 'Poor';
        }
    }

    // ============================================
    // STATIC METHODS
    // ============================================

    /**
     * Create snapshot for specific period
     */
    public static function createForPeriod($userId, $period = null)
    {
        $period = $period ?? now()->format('Y-m');
        $user = User::findOrFail($userId);

        // Calculate period boundaries
        $periodStart = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $periodEnd = Carbon::createFromFormat('Y-m', $period)->endOfMonth();

        // Count activities in period
        $postsCount = $user->communityPosts()
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->count();

        $commentsCount = $user->postComments()
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->count();

        // ✅ FIX: Safe order counting
        $ordersCount = 0;
        if (method_exists($user, 'orders') && DB::getSchemaBuilder()->hasColumn('orders', 'user_id')) {
            try {
                $ordersCount = $user->orders()
                    ->whereBetween('created_at', [$periodStart, $periodEnd])
                    ->count();
            } catch (\Exception $e) {
                Log::warning('[UserActivitySnapshot] Orders count failed for user ' . $userId, [
                    'error' => $e->getMessage(),
                    'period' => $period,
                ]);
                $ordersCount = 0;
            }
        }

        $totalActivity = $postsCount + $commentsCount + $ordersCount;

        // Calculate population average for the period
        $populationAvg = self::calculatePopulationAverage($period);

        // Calculate score (same weighting as UserActivityMetric)
        $activityScore = ($postsCount * 3) + ($commentsCount * 1) + ($ordersCount * 5);

        // Create or update snapshot
        return self::updateOrCreate(
            [
                'user_id' => $userId,
                'period' => $period,
            ],
            [
                'posts_count' => $postsCount,
                'comments_count' => $commentsCount,
                'orders_count' => $ordersCount,
                'total_activity' => $totalActivity,
                'activity_score' => $activityScore,
                'population_avg_activity' => $populationAvg,
            ]
        );
    }

    /**
     * Calculate population average for period
     */
    public static function calculatePopulationAverage($period)
    {
        $avg = self::where('period', $period)
            ->avg('total_activity');

        return (int) round($avg ?? 0);
    }

    /**
     * Create snapshots for all users
     */
    public static function createForAllUsers($period = null)
    {
        $period = $period ?? now()->format('Y-m');

        User::chunk(100, function ($users) use ($period) {
            foreach ($users as $user) {
                self::createForPeriod($user->id, $period);
            }
        });

        // Update population average after all snapshots created
        $populationAvg = self::calculatePopulationAverage($period);

        self::where('period', $period)
            ->update(['population_avg_activity' => $populationAvg]);
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    /**
     * Compare with previous period
     */
    public function compareToPrevious()
    {
        $previousPeriod = $this->period_date->copy()->subMonth()->format('Y-m');

        $previous = self::where('user_id', $this->user_id)
            ->where('period', $previousPeriod)
            ->first();

        if (!$previous) {
            return [
                'has_previous' => false,
                'change' => 0,
                'change_percent' => 0,
                'is_declining' => false,
            ];
        }

        $change = $this->total_activity - $previous->total_activity;
        $changePercent = $previous->total_activity > 0
            ? ($change / $previous->total_activity) * 100
            : 0;

        return [
            'has_previous' => true,
            'previous_activity' => $previous->total_activity,
            'current_activity' => $this->total_activity,
            'change' => $change,
            'change_percent' => round($changePercent, 2),
            'is_declining' => $changePercent < -20, // 20% decline threshold
            'is_improving' => $changePercent > 20,
        ];
    }

    /**
     * Get trend (last 6 months)
     */
    public function getTrend($months = 6)
    {
        $snapshots = self::where('user_id', $this->user_id)
            ->where('period', '<=', $this->period)
            ->orderBy('period', 'desc')
            ->limit($months)
            ->get();

        return $snapshots->map(function ($snapshot) {
            return [
                'period' => $snapshot->period_formatted,
                'activity' => $snapshot->total_activity,
                'score' => $snapshot->activity_score,
            ];
        });
    }
}
