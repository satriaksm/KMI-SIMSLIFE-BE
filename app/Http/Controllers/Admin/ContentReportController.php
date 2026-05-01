<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\ReportReason;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Notifications\ReportReviewedNotification;
use Illuminate\Support\Facades\Validator;
use App\Models\Product;
use App\Models\CommunityPost;
use App\Models\PostComment;
use App\Notifications\ReportNotification;
use Illuminate\Support\Facades\DB;

class ContentReportController extends Controller
{
    /**
     * List all content reports
     */
    public function index(Request $request)
    {
        $query = ContentReport::with([
            'reporter:id,name,email',
            'reviewer:id,name',
            'reason:id,reason_title,applies_to'
        ]);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->has('reportable_type')) {
            $query->where('reportable_type', $request->reportable_type);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('report_comment', 'like', "%{$search}%");
        }

        $reports = $query->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json($reports);
    }

    /**
     * Get single report detail
     */
    public function show($id)
    {
        $report = ContentReport::with([
            'reporter:id,name,email,phone',
            'reviewer:id,name',
            'reason:id,reason_title,applies_to',
            'reportable'
        ])->findOrFail($id);

        return response()->json(['data' => $report]);
    }

    /**
     * Review report (resolve, dismiss, in_review)
     */
    public function review(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:in_review,resolved,dismissed',
            'admin_note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $report = ContentReport::findOrFail($id);

        // Update status
        $report->update([
            'status' => $request->status,
            'reviewed_by' => Auth::id(),
            'admin_note' => $request->admin_note,
            'reviewed_at' => now(),
        ]);

        // Send notification to reporter
        try {
            $report->reporter->notify(new ReportReviewedNotification($report));
        } catch (\Exception $e) {
            Log::warning('Failed to send notification: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Report reviewed successfully',
            'data' => $report->fresh()->load(['reporter', 'reviewer', 'reason']),
        ]);
    }

    /**
     * Delete report
     */
    public function destroy($id)
    {
        $report = ContentReport::findOrFail($id);
        $report->delete();

        return response()->json([
            'message' => 'Report deleted successfully',
        ]);
    }

    /**
     * Get report reasons
     */
    public function reasons(Request $request)
    {
        try {
            $type = $request->input('type');

            $query = ReportReason::where('is_active', true);

            if ($type) {
                $query->where('applies_to', $type);
            }

            $reasons = $query->get();

            return response()->json($reasons);
        } catch (\Exception $e) {
            Log::error('[ContentReport] Reasons failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to load reasons',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Transform report data
     */
    private function transformReport($report, $detailed = false)
    {
        // Extract reportable_type
        $reportableType = null;
        if ($report->reportable_type) {
            $reportableType = strtolower(class_basename($report->reportable_type));

            // Handle specific cases
            if ($reportableType === 'communitypost') {
                $reportableType = 'post';
            } elseif ($reportableType === 'postcomment') {
                $reportableType = 'post_comment';
            } elseif ($reportableType === 'jasa') {
                $reportableType = 'service';
            }
        }

        $data = [
            'id' => $report->id,
            'status' => $report->status,
            'report_comment' => $report->report_comment,
            'reportable_type' => $reportableType,
            'reportable_id' => $report->reportable_id,
            'reporter' => $report->reporter ? [
                'id' => $report->reporter->id,
                'name' => $report->reporter->name,
                'email' => $report->reporter->email ?? null,
            ] : null,
            'reason' => $report->reason ? [
                'id' => $report->reason->id,
                'reason_title' => $report->reason->reason_title,
                'applies_to' => $report->reason->applies_to,
            ] : null,
            'reviewer' => $report->reviewer ? [
                'id' => $report->reviewer->id,
                'name' => $report->reviewer->name,
            ] : null,
            'admin_note' => $report->admin_note,
            'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            'created_at' => $report->created_at->toIso8601String(),
            'updated_at' => $report->updated_at->toIso8601String(),
        ];

        // Add reportable content details if detailed view
        if ($detailed && $report->reportable) {
            $data['reportable_content'] = $this->getReportableContent($report);
        }

        return $data;
    }

    /**
     * Get reportable content details
     */
    private function getReportableContent($report)
    {
        try {
            $reportable = $report->reportable;

            if (!$reportable) {
                return null;
            }

            $type = strtolower(class_basename($report->reportable_type));

            return match ($type) {
                'product' => [
                    'id' => $reportable->id,
                    'name' => $reportable->name,
                    'image' => $reportable->image,
                    'status' => $reportable->status,
                ],
                'communitypost' => [
                    'id' => $reportable->id,
                    'content' => substr($reportable->content, 0, 200),
                    'status' => $reportable->status,
                ],
                'postcomment' => [
                    'id' => $reportable->id,
                    'comment' => substr($reportable->comment, 0, 200),
                ],
                'jasa' => [
                    'id' => $reportable->id,
                    'title' => $reportable->title,
                ],
                'merchant' => [
                    'id' => $reportable->id,
                    'merchant_name' => $reportable->merchant_name,
                    'status' => $reportable->status,
                ],
                default => ['id' => $reportable->id],
            };
        } catch (\Exception $e) {
            Log::warning('[ContentReport] Failed to load reportable content', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Assign report to admin
     */
    public function assign(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'admin_id' => 'required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $report = ContentReport::findOrFail($id);

        $report->update([
            'reviewed_by' => $request->admin_id,
            'status' => 'in_review',
        ]);

        return response()->json([
            'message' => 'Report assigned successfully',
            'data' => $report->fresh(),
        ]);
    }

    /**
     * Resolve report (alias for review)
     */
    public function resolve(Request $request, $id)
    {
        return $this->review($request, $id);
    }

    /**
     * Forward report to merchant/user
     * POST /admin/reports/{id}/forward
     */
    public function forward(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'message' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $report = ContentReport::with(['reportable'])->findOrFail($id);

        DB::transaction(function () use ($report, $request) {
            // Update report
            $report->update([
                'forwarded_to' => $request->user_id,
                'forwarded_by' => Auth::id(),
                'forwarded_at' => now(),
                'forward_message' => $request->message,
                'status' => 'in_review',
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ]);

            // Send notification to target user
            $targetUser = \App\Models\User::find($request->user_id);
            if ($targetUser) {
                $targetUser->notify(new ReportNotification($report, 'forwarded'));
            }

            // Log action
            \App\Models\AdminAction::create([
                'admin_id' => Auth::id(),
                'action_type' => 'forward_report',
                'target_type' => ContentReport::class,
                'target_id' => $report->id,
                'reason' => 'Forwarded to user: ' . $targetUser->name,
                'metadata' => [
                    'forwarded_to' => $request->user_id,
                    'message' => $request->message,
                ],
            ]);
        });

        return response()->json([
            'message' => 'Report forwarded successfully',
            'data' => $report->fresh(['reporter', 'reviewer', 'reason', 'forwardedToUser']),
        ]);
    }

    /**
     * Suspend user related to report
     * POST /admin/reports/{id}/actions/suspend-user
     */
    public function suspendUser(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
            'duration_days' => 'nullable|integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $report = ContentReport::with(['reportable'])->findOrFail($id);

        // Get user to suspend
        $targetUser = $this->getUserFromReportable($report->reportable);

        if (!$targetUser) {
            return response()->json(['message' => 'Cannot determine user to suspend'], 400);
        }

        DB::transaction(function () use ($report, $targetUser, $request) {
            // Suspend user
            $oldStatus = $targetUser->status;
            $targetUser->update(['status' => 'suspended']);

            // Log admin action
            \App\Models\AdminAction::create([
                'admin_id' => Auth::id(),
                'action_type' => 'suspend_user',
                'target_type' => \App\Models\User::class,
                'target_id' => $targetUser->id,
                'reason' => $request->reason,
                'status_before' => $oldStatus,
                'status_after' => 'suspended',
                'metadata' => [
                    'duration_days' => $request->duration_days,
                    'expires_at' => $request->duration_days ? now()->addDays($request->duration_days) : null,
                    'via_report_id' => $report->id,
                ],
            ]);

            // Update report
            $report->update([
                'action_taken' => 'user_suspended',
                'status' => 'resolved',
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ]);

            // Notify reporter
            $report->reporter->notify(new ReportNotification($report, 'action_taken'));
        });

        return response()->json([
            'message' => 'User suspended successfully',
            'data' => $report->fresh(['reporter', 'reviewer', 'reason']),
        ]);
    }

    /**
     * Warn user related to report
     * POST /admin/reports/{id}/actions/warn-user
     */
    public function warnUser(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $report = ContentReport::with(['reportable'])->findOrFail($id);

        $targetUser = $this->getUserFromReportable($report->reportable);

        if (!$targetUser) {
            return response()->json(['message' => 'Cannot determine user to warn'], 400);
        }

        DB::transaction(function () use ($report, $targetUser, $request) {
            // Log admin action
            \App\Models\AdminAction::create([
                'admin_id' => Auth::id(),
                'action_type' => 'warn_user',
                'target_type' => \App\Models\User::class,
                'target_id' => $targetUser->id,
                'reason' => 'Warning via report',
                'metadata' => [
                    'message' => $request->message,
                    'via_report_id' => $report->id,
                ],
            ]);

            // Update report
            $report->update([
                'action_taken' => 'user_warned',
                'status' => 'resolved',
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
                'admin_note' => $request->message,
            ]);

            // Send warning notification
            $targetUser->notify(new ReportNotification($report, 'forwarded'));

            // Notify reporter
            $report->reporter->notify(new ReportNotification($report, 'action_taken'));
        });

        return response()->json([
            'message' => 'User warned successfully',
            'data' => $report->fresh(['reporter', 'reviewer', 'reason']),
        ]);
    }

    /**
     * Delete content related to report
     * POST /admin/reports/{id}/actions/delete-content
     */
    public function deleteContent(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
            'notify_owner' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $report = ContentReport::with(['reportable'])->findOrFail($id);

        $reportable = $report->reportable;

        if (!$reportable) {
            return response()->json(['message' => 'Reportable content not found'], 404);
        }

        DB::transaction(function () use ($report, $reportable, $request) {
            $actionType = 'content_deleted';

            // Handle deletion based on type
            if ($reportable instanceof Product) {
                $reportable->update(['status' => 'archived']);
                $actionType = 'product_archived';
            } elseif ($reportable instanceof CommunityPost) {
                $reportable->delete(); // soft delete
                $actionType = 'post_deleted';
            } elseif ($reportable instanceof PostComment) {
                $reportable->delete(); // soft delete
                $actionType = 'comment_deleted';
            }

            // Log admin action
            \App\Models\AdminAction::create([
                'admin_id' => Auth::id(),
                'action_type' => 'delete_content',
                'target_type' => get_class($reportable),
                'target_id' => $reportable->id,
                'reason' => $request->reason,
                'metadata' => [
                    'via_report_id' => $report->id,
                    'notify_owner' => $request->notify_owner ?? false,
                ],
            ]);

            // Update report
            $report->update([
                'action_taken' => $actionType,
                'status' => 'resolved',
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
                'admin_note' => $request->reason,
            ]);

            // Notify content owner if requested
            if ($request->notify_owner ?? false) {
                $owner = $this->getUserFromReportable($reportable);
                if ($owner) {
                    // TODO: Send notification to owner
                }
            }

            // Notify reporter
            $report->reporter->notify(new ReportNotification($report, 'action_taken'));
        });

        return response()->json([
            'message' => 'Content deleted successfully',
            'data' => $report->fresh(['reporter', 'reviewer', 'reason']),
        ]);
    }

    /**
     * Get report statistics for dashboard
     * GET /admin/reports/statistics
     */
    public function statistics(Request $request)
    {
        try {
            $period = $request->input('period', 'last_30_days');
            $startDate = $period === 'last_30_days' ? now()->subDays(30) : null;

            $query = ContentReport::query();

            if ($startDate) {
                $query->where('created_at', '>=', $startDate);
            }

            $stats = [
                'total' => (clone $query)->count(),
                'pending' => (clone $query)->where('status', 'pending')->count(),
                'in_review' => (clone $query)->where('status', 'in_review')->count(),
                'resolved' => (clone $query)->where('status', 'resolved')->count(),
                'dismissed' => (clone $query)->where('status', 'dismissed')->count(),
                'by_type' => ContentReport::selectRaw('reportable_type, COUNT(*) as count')
                    ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
                    ->groupBy('reportable_type')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'type' => $this->normalizeReportableType($item->reportable_type),
                            'count' => $item->count,
                        ];
                    }),
                'by_action' => ContentReport::selectRaw('action_taken, COUNT(*) as count')
                    ->when($startDate, fn($q) => $q->where('created_at', '>=', $startDate))
                    ->where('action_taken', '!=', 'none')
                    ->groupBy('action_taken')
                    ->get(),
            ];

            return response()->json($stats);
        } catch (\Exception $e) {
            Log::error('[ContentReport] Statistics failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to load statistics',
            ], 500);
        }
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    private function getUserFromReportable($reportable): ?\App\Models\User
    {
        if (!$reportable) return null;

        if ($reportable instanceof \App\Models\User) {
            return $reportable;
        }

        if ($reportable instanceof Product) {
            return $reportable->merchant?->user;
        }

        if ($reportable instanceof \App\Models\Merchant) {
            return $reportable->user;
        }

        if ($reportable instanceof CommunityPost || $reportable instanceof PostComment) {
            return $reportable->user;
        }

        return null;
    }

    private function normalizeReportableType(string $fullClass): string
    {
        $type = strtolower(class_basename($fullClass));
        
        return match($type) {
            'communitypost' => 'post',
            'postcomment' => 'post_comment',
            default => $type,
        };
    }
}
