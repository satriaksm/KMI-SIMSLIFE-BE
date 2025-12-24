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
}
