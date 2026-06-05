<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\AdminAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Barryvdh\DomPDF\Facade\Pdf;

class AdminManagementController extends Controller
{
    /**
     * List all admins (System Admin only)
     */
    public function index(Request $request)
    {
        // Validate Super Admin
        if (!Auth::user() || !Auth::user()->is_super_admin) {
            return response()->json([
                'message' => 'Forbidden. Only Super Admin can manage admins.'
            ], 403);
        }

        $query = User::whereHas('roles', function ($q) {
            $q->where('name', 'admin');
        })
        ->with(['roles:id,name'])
        ->select('id', 'name', 'email', 'status', 'is_super_admin', 'created_at');

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $admins = $query->paginate($request->input('per_page', 15));

        return response()->json($admins);
    }

    /**
     * Create new admin (System Admin only)
     */
    public function store(Request $request)
    {
        if (!Auth::user() || !Auth::user()->is_super_admin) {
            return response()->json([
                'message' => 'Forbidden. Only Super Admin can create admins.'
            ], 403);
        }

        // PREVENT creating super admin via API
        if ($request->has('is_super_admin') && $request->is_super_admin) {
            return response()->json([
                'message' => 'Cannot create Super Admin via API.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols()
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            // Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'status' => 'active',
                'email_verified_at' => now(),
                'is_super_admin' => false, 
            ]);

            // Assign role 'admin'
            $adminRole = Role::where('name', 'admin')->firstOrFail();
            $user->roles()->attach($adminRole->id);

            AdminAction::create([
                'admin_id' => Auth::id(),
                'action_type' => 'manual_override',
                'target_type' => User::class,
                'target_id' => $user->id,
                'reason' => 'Created new admin',
                'metadata' => [
                    'new_admin_email' => $user->email,
                    'is_super_admin' => false,
                ],
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Admin created successfully',
                'data' => $user->load('roles:id,name'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create admin',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle admin status (System Admin only)
     */
    public function toggleStatus(Request $request, $id)
    {
        if (!Auth::user() || !Auth::user()->is_super_admin) {
            return response()->json([
                'message' => 'Forbidden. Only Super Admin can change admin status.'
            ], 403);
        }

        $targetUser = User::findOrFail($id);

        // PREVENT: Deactivating self
        if ($targetUser->id === Auth::id()) {
            return response()->json([
                'message' => 'Cannot deactivate yourself.'
            ], 400);
        }

        // PREVENT: Deactivating Super Admin
        if ($targetUser->is_super_admin) {
            return response()->json([
                'message' => 'Super Admin cannot be deactivated.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $oldStatus = $targetUser->status;
        $newStatus = $oldStatus === 'active' ? 'suspended' : 'active';

        DB::beginTransaction();
        try {
            $targetUser->update(['status' => $newStatus]);

            // Log action
            AdminAction::create([
                'admin_id' => Auth::id(),
                'action_type' => 'status_change',
                'target_type' => User::class,
                'target_id' => $targetUser->id,
                'reason' => $request->reason,
                'status_before' => $oldStatus,
                'status_after' => $newStatus,
                'metadata' => [
                    'is_super_admin' => $targetUser->is_super_admin,
                ],
            ]);

            // Revoke active sessions if suspended
            if ($newStatus === 'suspended') {
                $targetUser->tokens()->delete();
            }

            DB::commit();

            return response()->json([
                'message' => "Admin {$newStatus} successfully",
                'data' => $targetUser->fresh(['roles']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update admin status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete admin (System Admin only)
     */
    public function destroy(Request $request, $id)
    {
        if (!Auth::user() || !Auth::user()->is_super_admin) {
            return response()->json([
                'message' => 'Forbidden. Only Super Admin can delete admins.'
            ], 403);
        }

        $targetUser = User::findOrFail($id);

        // PREVENT: Deleting self
        if ($targetUser->id === Auth::id()) {
            return response()->json([
                'message' => 'Cannot delete yourself.'
            ], 400);
        }

        // PREVENT: Deleting Super Admin
        if ($targetUser->is_super_admin) {
            return response()->json([
                'message' => 'Super Admin cannot be deleted.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            // Log action BEFORE deletion
            AdminAction::create([
                'admin_id' => Auth::id(),
                'action_type' => 'manual_override',
                'target_type' => User::class,
                'target_id' => $targetUser->id,
                'reason' => $request->reason,
                'metadata' => [
                    'deleted_admin_email' => $targetUser->email,
                    'is_super_admin' => $targetUser->is_super_admin,
                ],
            ]);

            $targetUser->delete();

            DB::commit();

            return response()->json([
                'message' => 'Admin deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete admin',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show admin activity logs
     */
    public function activityLogs(Request $request, $id)
    {
        if (!Auth::user() || !Auth::user()->is_super_admin) {
            return response()->json([
                'message' => 'Forbidden.'
            ], 403);
        }

        $admin = User::findOrFail($id);

        $logs = AdminAction::where('admin_id', $admin->id)
            ->with(['target' => function ($morphTo) {
                $morphTo->morphWith([
                    User::class => ['roles:id,name'],
                ]);
            }])
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'admin' => $admin->only(['id', 'name', 'email', 'is_super_admin']),
            'logs' => $logs,
        ]);
    }

    public function exportPdf(Request $request)
    {
        try {
            if (!Auth::user() || !Auth::user()->is_super_admin) {
                return response()->json([
                    'message' => 'Forbidden. Only Super Admin can export.'
                ], 403);
            }

            $admin = $request->user();

            $query = User::whereHas('roles', function ($q) {
                $q->where('name', 'admin');
            })->with(['roles:id,name']);

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            $admins = $query->latest()->limit(500)->get();

            $metadata = [
                'generated_at' => now()->format('d F Y, H:i:s'),
                'generated_by' => $admin->name ?? 'Super Admin',
                'generated_by_email' => $admin->email ?? '-',
                'total_admins' => $admins->count(),
                'filters' => [
                    'status' => $request->input('status') ?: 'Semua',
                    'search' => $request->input('search') ?: '-',
                ],
            ];

            $logoPath = public_path('images/logo-sumilir.png');
            $logoBase64 = '';
            
            if (file_exists($logoPath)) {
                $logoData = file_get_contents($logoPath);
                $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
            }

            $pdf = Pdf::loadView('exports.admin.admin-system', [
                'admins' => $admins,
                'metadata' => $metadata,
                'logoBase64' => $logoBase64,
            ])
                ->setPaper('a4', 'portrait')
                ->setOption('margin-top', 10)
                ->setOption('margin-right', 10)
                ->setOption('margin-bottom', 10)
                ->setOption('margin-left', 10);

            $filename = 'admins-report-' . now()->format('Ymd-His') . '.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('[AdminManagement] Export PDF failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat laporan PDF',
            ], 500);
        }
    }

    public function exportAdminDetailPdf(Request $request, $id)
    {
        try {
            if (!Auth::user() || !Auth::user()->is_super_admin) {
                return response()->json([
                    'message' => 'Forbidden.'
                ], 403);
            }

            $currentAdmin = $request->user();
            
            $admin = User::with(['roles:id,name'])->findOrFail($id);

            $activityLogs = AdminAction::where('admin_id', $admin->id)
                ->latest()
                ->limit(50)
                ->get();

            $metadata = [
                'generated_at' => now()->format('d F Y, H:i:s'),
                'generated_by' => $currentAdmin->name ?? 'Super Admin',
                'generated_by_email' => $currentAdmin->email ?? '-',
            ];

            $logoPath = public_path('images/logo-sumilir.png');
            $logoBase64 = '';
            
            if (file_exists($logoPath)) {
                $logoData = file_get_contents($logoPath);
                $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
            }

            $pdf = Pdf::loadView('exports.admin.admin-system-detail', [
                'admin' => $admin,
                'activityLogs' => $activityLogs,
                'metadata' => $metadata,
                'logoBase64' => $logoBase64,
            ])
                ->setPaper('a4', 'portrait')
                ->setOption('margin-top', 10)
                ->setOption('margin-right', 10)
                ->setOption('margin-bottom', 10)
                ->setOption('margin-left', 10);

            $filename = 'admin-detail-' . $admin->id . '-' . now()->format('Ymd-His') . '.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            Log::error('[AdminManagement] Export admin detail PDF failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat laporan PDF',
            ], 500);
        }
    }
}