<?php

namespace App\Http\Controllers;

use App\Models\ContentReport;
use App\Models\ReportAppeal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ReportAppealController extends Controller
{
    /**
     * Submit sanggahan (user/merchant terlapor)
     * POST /reports/{reportId}/appeal
     */
    public function store(Request $request, $reportId)
    {
        $validator = Validator::make($request->all(), [
            'appeal_text' => 'required|string|min:5|max:3000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $report = ContentReport::with(['reportable', 'reporter'])->findOrFail($reportId);

        // Pastikan user adalah pihak yang terkena tindakan (bukan pelapor)
        $targetUser = $report->getTargetUser();
        $currentUser = Auth::user();

        if (!$targetUser || $targetUser->id !== $currentUser->id) {
            return response()->json([
                'message' => 'Anda tidak berwenang mengajukan sanggahan untuk laporan ini',
            ], 403);
        }

        // Cek apakah sudah ada sanggahan sebelumnya
        $existing = ReportAppeal::where('content_report_id', $reportId)
            ->where('user_id', $currentUser->id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Anda sudah mengajukan sanggahan untuk laporan ini',
                'data'    => $existing,
            ], 409);
        }

        // Laporan harus sudah di-resolve dengan action_taken
        if ($report->status !== 'resolved' || !$report->action_taken) {
            return response()->json([
                'message' => 'Sanggahan hanya dapat diajukan setelah admin mengambil tindakan',
            ], 422);
        }

        $appeal = DB::transaction(function () use ($report, $request, $currentUser) {
            $appeal = ReportAppeal::create([
                'content_report_id' => $report->id,
                'user_id'           => $currentUser->id,
                'appeal_text'       => $request->appeal_text,
                'status'            => 'pending',
            ]);

            // Notify admins
            try {
                $admins = User::whereHas('roles', fn($q) => $q->where('name', 'admin'))
                    ->orWhere('is_super_admin', true)
                    ->get();

                foreach ($admins as $admin) {
                    $admin->notify(
                        new \App\Notifications\NewAppealAdminNotification($appeal, $report)
                    );
                }
            } catch (\Exception $e) {
                Log::warning('[ReportAppeal] Notify admin failed: ' . $e->getMessage());
            }

            return $appeal;
        });

        return response()->json([
            'message' => 'Sanggahan berhasil dikirim',
            'data'    => $appeal->load(['appellant:id,name']),
        ], 201);
    }

    /**
     * Get appeals for a specific report (user view — hanya lihat milik sendiri)
     * GET /reports/{reportId}/appeals
     */
    public function index($reportId)
    {
        $report  = ContentReport::findOrFail($reportId);
        $appeals = ReportAppeal::with(['appellant:id,name', 'respondent:id,name'])
            ->where('content_report_id', $reportId)
            ->where('user_id', Auth::id())
            ->latest()
            ->get();

        return response()->json(['data' => $appeals]);
    }
}
