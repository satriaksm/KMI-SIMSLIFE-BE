<?php

namespace App\Http\Controllers;

use App\Models\ContentReport;
use App\Models\ReportReason;
use App\Models\Product;
use App\Models\Merchant;
use App\Models\CommunityPost;
use App\Models\PostComment;
use App\Models\Jasa;
use App\Models\User;
use App\Notifications\NewReportAdminNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    /**
     * Get report reasons (active only).
     */
    public function reasons(Request $request)
    {
        $type = $this->normalizeTypeKey($request->input('type'));

        $query = ReportReason::query()->where('is_active', true);

        if ($type) {
            $query->where('applies_to', $type);
        }

        return response()->json($query->get());
    }

    /**
     * Submit a new report.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reportable_type' => 'required|string|in:product,service,merchant,post,post_comment,user',
            'reportable_id' => 'required|integer',
            'report_reason_id' => 'required|integer|exists:report_reasons,id',
            'report_comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $typeKey = $this->normalizeTypeKey($request->input('reportable_type'));
        $reportable = $this->findReportable($typeKey, (int) $request->input('reportable_id'));

        if (!$reportable) {
            return response()->json([
                'message' => 'Konten yang dilaporkan tidak ditemukan',
            ], 404);
        }

        $reason = ReportReason::where('id', $request->input('report_reason_id'))
            ->where('applies_to', $typeKey)
            ->where('is_active', true)
            ->first();

        if (!$reason) {
            return response()->json([
                'message' => 'Alasan laporan tidak sesuai dengan tipe konten',
            ], 422);
        }

        if (strtolower($reason->reason_title) === 'lainnya' && empty(trim((string) $request->input('report_comment')))) {
            return response()->json([
                'message' => 'Keterangan wajib diisi untuk alasan "Lainnya"',
            ], 422);
        }

        if ($this->isSelfReport($typeKey, $reportable, $request->user())) {
            return response()->json([
                'message' => 'Tidak dapat melaporkan konten sendiri',
            ], 403);
        }

        $reportableClass = $this->resolveReportableClass($typeKey);

        $existingReport = ContentReport::where('user_id', $request->user()->id)
            ->where('reportable_type', $reportableClass)
            ->where('reportable_id', $reportable->id)
            ->first();

        if ($existingReport) {
            return response()->json([
                'message' => 'Anda sudah pernah melaporkan konten ini',
            ], 409);
        }

        $report = ContentReport::create([
            'user_id'          => $request->user()->id,
            'reportable_type'  => $reportableClass,
            'reportable_id'    => $reportable->id,
            'report_reason_id' => $reason->id,
            'report_comment'   => $request->input('report_comment'),
            'status'           => 'pending',
        ]);

        $report->load(['reason:id,reason_title,reason_description', 'reviewer:id,name', 'reportable']);

        // Notify all admins via email in the background
        defer(function () use ($report) {
            try {
                $admins = User::whereHas('roles', fn($q) => $q->where('name', 'admin'))
                    ->orWhere('is_super_admin', true)
                    ->get();

                foreach ($admins as $index => $admin) {
                    // Add a 3-second delay between each email to prevent Mailtrap rate limiting
                    $admin->notify((new NewReportAdminNotification($report))->delay(now()->addSeconds($index * 3)));
                }
            } catch (\Exception $e) {
                Log::warning('[ReportController] Failed to notify admins: ' . $e->getMessage());
            }
        });

        return response()->json([
            'message' => 'Laporan berhasil dikirim',
            'data'    => $this->transformReportForUser($report),
        ], 201);
    }

    /**
     * List reports created by current user.
     */
    public function myReports(Request $request)
    {
        $reports = ContentReport::with(['reason:id,reason_title,reason_description', 'reviewer:id,name', 'reportable'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate($request->input('per_page', 15));

        $reports->setCollection(
            $reports->getCollection()->map(fn ($report) => $this->transformReportForUser($report))
        );

        return response()->json($reports);
    }

    /**
     * Show a report detail (owned by user OR targeting the user).
     */
    public function show(Request $request, $id)
    {
        $report = ContentReport::with(['reason:id,reason_title,reason_description', 'reviewer:id,name', 'reportable'])
            ->findOrFail($id);

        $currentUser = $request->user();
        $isReporter = $report->user_id === $currentUser->id;
        
        $targetUser = $report->getTargetUser();
        $isTarget = $targetUser && $targetUser->id === $currentUser->id;

        if (!$isReporter && !$isTarget) {
            abort(403, 'Anda tidak berhak melihat laporan ini');
        }

        return response()->json([
            'data' => array_merge($this->transformReportForUser($report), [
                'is_target' => $isTarget,
                'action_taken' => $report->action_taken, // ensure action_taken is included
            ]),
        ]);
    }

    private function normalizeTypeKey(?string $type): ?string
    {
        if (!$type) return null;

        $raw = class_basename($type);
        $lower = strtolower($raw);

        if ($lower === 'communitypost') return 'post';
        if ($lower === 'postcomment') return 'post_comment';
        if ($lower === 'jasa') return 'service';

        return $lower;
    }

    private function resolveReportableClass(string $typeKey): string
    {
        return match ($typeKey) {
            'product' => Product::class,
            'service' => Jasa::class,
            'merchant' => Merchant::class,
            'post' => CommunityPost::class,
            'post_comment' => PostComment::class,
            'user' => User::class,
            default => Product::class,
        };
    }

    private function findReportable(string $typeKey, int $id)
    {
        return match ($typeKey) {
            'product' => Product::with('merchant')->find($id),
            'service' => Jasa::with('merchant')->find($id),
            'merchant' => Merchant::find($id),
            'post' => CommunityPost::find($id),
            'post_comment' => PostComment::find($id),
            'user' => User::find($id),
            default => null,
        };
    }

    private function isSelfReport(string $typeKey, $reportable, User $reporter): bool
    {
        return match ($typeKey) {
            'user' => $reportable->id === $reporter->id,
            'merchant' => $reportable->user_id === $reporter->id,
            'product' => $reportable->merchant?->user_id === $reporter->id,
            'service' => $reportable->merchant?->user_id === $reporter->id,
            'post' => $reportable->user_id === $reporter->id,
            'post_comment' => $reportable->user_id === $reporter->id,
            default => false,
        };
    }

    private function transformReportForUser(ContentReport $report): array
    {
        $typeKey = $this->normalizeTypeKey($report->reportable_type);

        return [
            'id' => $report->id,
            'status' => $report->status,
            'report_comment' => $report->report_comment,
            'reportable_type' => $typeKey,
            'reportable_id' => $report->reportable_id,
            'reportable_name' => $this->getReportableName($report->reportable, $typeKey),
            'reason' => $report->reason ? [
                'id' => $report->reason->id,
                'reason_title' => $report->reason->reason_title,
                'reason_description' => $report->reason->reason_description,
            ] : null,
            'admin_note' => $report->admin_note,
            'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            'reviewed_by' => $report->reviewer?->name,
            'created_at' => $report->created_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];
    }

    private function getReportableName($reportable, string $typeKey): string
    {
        if (!$reportable) {
            return 'Konten tidak ditemukan';
        }

        return match ($typeKey) {
            'product' => $reportable->name ?? 'Produk',
            'service' => $reportable->title ?? 'Jasa',
            'merchant' => $reportable->name ?? 'Merchant',
            'post' => $reportable->post_title
                ?? Str::limit((string) $reportable->post_content, 80)
                ?? 'Postingan',
            'post_comment' => Str::limit((string) $reportable->comment_content, 80) ?? 'Komentar',
            'user' => $reportable->name ?? 'Pengguna',
            default => $reportable->name ?? $reportable->title ?? "Konten #{$reportable->id}",
        };
    }
}
