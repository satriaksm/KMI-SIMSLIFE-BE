<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\ReportAppeal;
use App\Models\User;
use App\Notifications\NewReportAdminNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Mail\Message;

class ReportAppealController extends Controller
{
    /**
     * List semua appeal (admin view)
     * GET /admin/reports/{reportId}/appeals
     */
    public function index($reportId)
    {
        $report  = ContentReport::findOrFail($reportId);
        $appeals = ReportAppeal::with(['appellant:id,name,email', 'respondent:id,name'])
            ->where('content_report_id', $reportId)
            ->latest()
            ->get();

        return response()->json([
            'data' => $appeals,
        ]);
    }

    /**
     * Review/respond to an appeal (admin)
     * PATCH /admin/reports/{reportId}/appeals/{appealId}/review
     */
    public function review(Request $request, $reportId, $appealId)
    {
        $validator = Validator::make($request->all(), [
            'status'         => 'required|in:accepted,rejected,reviewed',
            'admin_response' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $report = ContentReport::with(['reporter'])->findOrFail($reportId);
        $appeal = ReportAppeal::with(['appellant'])->where('content_report_id', $reportId)->findOrFail($appealId);

        DB::transaction(function () use ($appeal, $report, $request) {
            $appeal->update([
                'status'         => $request->status,
                'admin_response' => $request->admin_response,
                'responded_by'   => Auth::id(),
                'responded_at'   => now(),
            ]);

            // Jika admin menyetujui sanggahan, pulihkan (restore) konten/user yang terkena sanksi
            if ($request->status === 'accepted' && $report->reportable) {
                $reportable = $report->reportable;
                $action = $report->action_taken;
                
                // Revert Product / Service
                if (in_array($action, ['archive_product', 'archive_service']) && isset($reportable->status)) {
                    $reportable->update(['status' => 'published']);
                }
                // Revert User
                elseif (in_array($action, ['suspend_user', 'deactivate_user'])) {
                    $targetUser = $report->getTargetUser();
                    if ($targetUser && isset($targetUser->status)) {
                        $targetUser->update(['status' => 'active']);
                    }
                }
                // Revert Merchant
                elseif (in_array($action, ['suspend_merchant', 'archive_merchant'])) {
                    if ($reportable instanceof \App\Models\Merchant) {
                        $reportable->update(['status' => 'approved']);
                    } elseif ($reportable instanceof \App\Models\Product || $reportable instanceof \App\Models\Jasa) {
                        $reportable->merchant?->update(['status' => 'approved']);
                    }
                }
                // Revert Post/Comment
                elseif (in_array($action, ['delete_post', 'delete_comment'])) {
                    if ($reportable instanceof \App\Models\CommunityPost && isset($reportable->post_status)) {
                        // Publish ulang postingan
                        $postData = ['post_status' => 'published'];
                        if ($report->original_content) {
                            $postData['post_content'] = $report->original_content;
                        }
                        $reportable->update($postData);
                    } elseif ($reportable instanceof \App\Models\PostComment) {
                        // Kembalikan teks komentar asli jika tersimpan
                        if ($report->original_content) {
                            $reportable->update(['comment_content' => $report->original_content]);
                        }
                    }
                }
            }

            // Kirim email ke appellant (user yang mengajukan sanggahan)
            try {
                if ($appeal->appellant) {
                    $appeal->appellant->notify(
                        new \App\Notifications\AppealReviewedNotification($appeal, $report)
                    );
                }
            } catch (\Exception $e) {
                Log::warning('[ReportAppeal] Notify appellant failed: ' . $e->getMessage());
            }
        });

        return response()->json([
            'message' => 'Sanggahan berhasil ditinjau',
            'data'    => $appeal->fresh()->load(['appellant:id,name,email', 'respondent:id,name']),
        ]);
    }
}
