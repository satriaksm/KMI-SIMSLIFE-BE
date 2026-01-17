<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserActivityMetric;
use App\Models\UserActivitySnapshot;
use App\Models\Alert;
use App\Models\ContentReport;
use App\Models\AdminAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ComputeUserStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:compute-status 
                            {--user-id= : Compute status for specific user}
                            {--force : Force recompute even if recently updated}
                            {--create-alerts : Create alerts for status changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compute user status based on activity metrics, reports, and engagement patterns';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $userId = $this->option('user-id');
        $force = $this->option('force');
        $createAlerts = $this->option('create-alerts');

        try {
            //Update all user activity metrics
            $this->updateActivityMetrics($userId);

            //Create monthly snapshots if needed
            if (!$userId) {
                $this->createMonthlySnapshots();
            }

            //Compute status for each user
            $users = $this->getUsersToProcess($userId, $force);

            $bar = $this->output->createProgressBar($users->count());
            $bar->start();

            $statusChanges = [];

            foreach ($users as $user) {
                $result = $this->computeUserStatus($user, $createAlerts);
                if ($result['status_changed']) {
                    $statusChanges[] = $result;
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            // Summary
            $this->displaySummary($statusChanges);

            return Command::SUCCESS;
        } catch (Exception $e) {
            Log::error('[ComputeUserStatus] Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error('Failed to compute user status: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Update activity metrics for all users
     */
    private function updateActivityMetrics($userId = null)
    {

        $query = User::query();
        if ($userId) {
            $query->where('id', $userId);
        }

        $users = $query->get();
        $startDate = now()->subDays(30);

        foreach ($users as $user) {
            // Get or create metric
            $metric = UserActivityMetric::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'posts_30d' => 0,
                    'comments_30d' => 0,
                    'orders_30d' => 0,
                    'total_activity_30d' => 0,
                    'reports_validated_30d' => 0,
                    'reports_total' => 0,
                    'activity_score' => 0,
                ]
            );

            // Count activities (last 30 days)
            $posts30d = $user->communityPosts()
                ->where('created_at', '>=', $startDate)
                ->count();

            $comments30d = $user->postComments()
                ->where('created_at', '>=', $startDate)
                ->count();

            // Check if orders relation exists before counting
            $orders30d = 0;
            if (method_exists($user, 'orders') && DB::getSchemaBuilder()->hasColumn('orders', 'user_id')) {
                try {
                    $orders30d = $user->orders()
                        ->where('created_at', '>=', $startDate)
                        ->count();
                } catch (Exception $e) {
                    Log::warning('[ComputeUserStatus] Orders count failed for user ' . $user->id, [
                        'error' => $e->getMessage(),
                    ]);
                    $orders30d = 0;
                }
            }

            // Count validated reports (last 30 days)
            $reportsValidated30d = ContentReport::where('user_id', $user->id)
                ->whereIn('status', ['resolved', 'in_review'])
                ->where('created_at', '>=', $startDate)
                ->count();

            // Count total reports (all time)
            $reportsTotal = ContentReport::where('user_id', $user->id)
                ->whereIn('status', ['resolved', 'in_review'])
                ->count();

            // Update last login
            $lastLogin = DB::table('sessions')
                ->where('user_id', $user->id)
                ->orderBy('last_activity', 'desc')
                ->value('last_activity');

            $lastLoginAt = $lastLogin ? Carbon::createFromTimestamp($lastLogin) : null;
            $lastLoginDays = $lastLoginAt ? now()->diffInDays($lastLoginAt) : 999;

            // Calculate activity score
            $activityScore = ($posts30d * 3) + ($comments30d * 1) + ($orders30d * 5);
            $activityScore -= ($reportsValidated30d * 10); // Penalty for reports
            $activityScore = max(0, $activityScore);

            // Detect spam pattern
            $spamDetected = $this->detectSpamPattern(
                $posts30d,
                $comments30d,
                $reportsValidated30d,
                $posts30d + $comments30d + $orders30d
            );

            // Update metric
            $metric->update([
                'posts_30d' => $posts30d,
                'comments_30d' => $comments30d,
                'orders_30d' => $orders30d,
                'total_activity_30d' => $posts30d + $comments30d + $orders30d,
                'reports_validated_30d' => $reportsValidated30d,
                'reports_total' => $reportsTotal,
                'last_login_at' => $lastLoginAt,
                'last_login_days' => $lastLoginDays,
                'activity_score' => $activityScore,
                'pattern_spam_detected' => $spamDetected,
            ]);
        }
    }

    /**
     * Create monthly snapshots
     */
    private function createMonthlySnapshots()
    {

        $currentPeriod = now()->format('Y-m');
        $lastPeriod = now()->subMonth()->format('Y-m');

        // Create snapshots for current period
        UserActivitySnapshot::createForAllUsers($currentPeriod);

        // Ensure last period exists
        $hasLastPeriod = UserActivitySnapshot::where('period', $lastPeriod)->exists();
        if (!$hasLastPeriod) {
            UserActivitySnapshot::createForAllUsers($lastPeriod);
        }
    }

    /**
     * Get users to process
     */
    private function getUsersToProcess($userId, $force)
    {
        $query = User::with(['activityMetric']);

        if ($userId) {
            $query->where('id', $userId);
        } elseif (!$force) {
            // Only process users updated more than 1 hour ago
            $query->where(function ($q) {
                $q->where('updated_at', '<', now()->subHour())
                    ->orWhereNull('updated_at');
            });
        }

        return $query->get();
    }

    /**
     * Compute status for single user
     */
    private function computeUserStatus(User $user, bool $createAlerts = false): array
    {
        $metric = $user->activityMetric;
        $oldStatus = $user->status; // Changed from computed_status

        // Default: active
        $newStatus = 'active';
        $reason = 'Normal activity';
        $priority = 'low';
        $recommendedActions = [];

        $lastStatusChange = AdminAction::where('target_type', User::class)
            ->where('target_id', $user->id)
            ->where('action_type', 'status_change')
            ->orderBy('created_at', 'desc')
            ->first();

        $dwellTimeDays = 7;
        $isLocked = false;

        if ($lastStatusChange && $lastStatusChange->created_at->diffInDays(now()) < $dwellTimeDays) {
            $isLocked = true;
        }

        // RULE 1: SUSPENDED (10+ validated reports in 30 days)
        // Emergency override: Bypass lock if critical threshold
        if ($metric && $metric->reports_validated_30d >= 10) {
            $isLocked = false; // Emergency override
            $newStatus = 'suspended';
            $reason = "User has {$metric->reports_validated_30d} validated reports in last 30 days";
            $priority = 'critical';
            $recommendedActions = [
                'Suspend user account immediately',
                'Review reported content',
                'Send suspension notification',
            ];
        }
        // RULE 2: WATCHLIST (5-9 validated reports)
        elseif ($metric && $metric->reports_validated_30d >= 5) {
            $newStatus = 'watchlist';
            $reason = "User has {$metric->reports_validated_30d} validated reports - needs monitoring";
            $priority = 'high';
            $recommendedActions = [
                'Monitor user activity closely',
                'Send warning notification',
                'Review recent posts/comments',
            ];
        }
        // RULE 3: SPAM PATTERN DETECTED
        elseif ($metric && $metric->pattern_spam_detected) {
            $newStatus = 'watchlist';
            $reason = 'Suspicious activity pattern detected (possible spam)';
            $priority = 'high';
            $recommendedActions = [
                'Verify account authenticity',
                'Review posting patterns',
                'Consider limiting posting frequency',
            ];
        }
        // RULE 4: DECLINING ACTIVITY (compared to previous month)
        elseif ($this->isDecliningActivity($user)) {
            $newStatus = 'declining';
            $reason = 'User activity has declined significantly compared to previous month';
            $priority = 'medium';
            $recommendedActions = [
                'Send re-engagement campaign',
                'Invite to events/paguyuban',
                'Offer vouchers/promotions',
            ];
        }
        // RULE 5: DORMANT (no login > 30 days)
        elseif ($metric && $metric->last_login_days > 30) {
            $newStatus = 'inactive';
            $reason = "User hasn't logged in for {$metric->last_login_days} days";
            $priority = 'low';
            $recommendedActions = [
                'Send re-activation email',
                'Offer special welcome-back promotion',
            ];
        }

        // APPLY STATUS LOCK (if not emergency)
        if ($isLocked && $newStatus !== $oldStatus) {
            $newStatus = $oldStatus; // Keep old status
            $reason .= ' (Status locked - dwell time not met)';
        }

        // Update user status
        $statusChanged = $oldStatus !== $newStatus;

        $user->update([
            'status' => $newStatus, // Changed from computed_status
        ]);

        // Create alert if status changed
        if ($statusChanged && $createAlerts && in_array($newStatus, ['suspended', 'watchlist', 'declining'])) {
            $this->createAlert($user, $newStatus, $reason, $priority, $recommendedActions);
        }

        return [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'status_changed' => $statusChanged,
            'reason' => $reason,
            'priority' => $priority,
        ];
    }

    /**
     * Check if user has declining activity
     */
    private function isDecliningActivity(User $user): bool
    {
        $currentPeriod = now()->format('Y-m');
        $previousPeriod = now()->subMonth()->format('Y-m');

        $currentSnapshot = UserActivitySnapshot::where('user_id', $user->id)
            ->where('period', $currentPeriod)
            ->first();

        $previousSnapshot = UserActivitySnapshot::where('user_id', $user->id)
            ->where('period', $previousPeriod)
            ->first();

        if (!$currentSnapshot || !$previousSnapshot) {
            return false;
        }

        // Compare with previous month
        if ($previousSnapshot->total_activity === 0) {
            return false;
        }

        $decline = (($previousSnapshot->total_activity - $currentSnapshot->total_activity) / $previousSnapshot->total_activity) * 100;

        // Declining if drop > 30%
        return $decline > 30;
    }

    /**
     * Detect spam pattern
     */
    private function detectSpamPattern(int $posts, int $comments, int $reports, int $totalActivity): bool
    {
        // Pattern 1: High post frequency with low comments (bot-like)
        if ($posts > 50 && $comments < 5) {
            return true;
        }

        // Pattern 2: High report ratio
        if ($reports >= 3 && $totalActivity > 0) {
            $reportRatio = $reports / $totalActivity;
            if ($reportRatio > 0.3) { // 30% of activities reported
                return true;
            }
        }

        return false;
    }

    /**
     * Create alert for status change
     */
    private function createAlert(User $user, string $status, string $reason, string $priority, array $actions)
    {
        $alertType = match ($status) {
            'suspended' => 'suspended_user',
            'watchlist' => 'watchlist_threshold',
            'declining' => 'declining_activity',
            default => 'high_reports',
        };

        Alert::create([
            'alertable_type' => User::class,
            'alertable_id' => $user->id,
            'alert_type' => $alertType,
            'priority' => $priority,
            'status' => 'pending',
            'message' => $reason,
            'recommended_actions' => $actions,
            'metadata' => [
                'previous_status' => $user->getOriginal('status'), // Changed from computed_status
                'new_status' => $status,
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Display summary
     */
    private function displaySummary(array $statusChanges)
    {
        if (empty($statusChanges)) {
            $this->info('No status changes detected');
            return;
        }

        $this->newLine();
        $this->table(
            ['User ID', 'User Name', 'Old Status', 'New Status', 'Reason'],
            array_map(fn($change) => [
                $change['user_id'],
                $change['user_name'],
                $change['old_status'],
                $change['new_status'],
                substr($change['reason'], 0, 50) . '...',
            ], $statusChanges)
        );

        // Count by status
        $statusCounts = collect($statusChanges)
            ->groupBy('new_status')
            ->map->count();

        $this->newLine();
        $this->info('Status Distribution:');
        foreach ($statusCounts as $status => $count) {
            $this->line("  • {$status}: {$count} users");
        }
    }
}
