<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Merchant;
use App\Models\Role;
use App\Models\AdminAction;
use App\Models\Alert;
use App\Models\UserActivityMetric;
use App\Models\ContentReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AdminUserController extends Controller
{
    /**
     * Get dashboard statistics and alerts
     *
     * Returns dashboard overview, user status distribution, trend, and top alerts.
     *
     * @authenticated
     *
     * @queryParam period integer Number of days for stats (default: 30). Example: 30
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "overview": { ... },
     *     "status_distribution": { ... },
     *     "user_trend": [ ... ],
     *     "top_alerts": [ ... ]
     *   }
     * }
     */
    // ============================================
    // DASHBOARD & OVERVIEW
    // ============================================

    /**
     * Get dashboard statistics and alerts
     */
    /**
     * Get dashboard statistics and alerts
     *
     * Returns dashboard overview, user status distribution, trend, and top alerts.
     *
     * @authenticated
     *
     * @queryParam period integer Number of days for stats (default: 30). Example: 30
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "overview": { ... },
     *     "status_distribution": { ... },
     *     "user_trend": [ ... ],
     *     "top_alerts": [ ... ]
     *   }
     * }
     */
    public function dashboard(Request $request)
    {
        try {
            $period = $request->input('period', 30);

            // Get current stats
            $currentStats = $this->getOverviewStats($period);

            // Get previous period stats for delta calculation
            $previousStats = $this->getOverviewStats($period * 2); 

            // Format overview with current and previous
            $overview = [
                'active' => [
                    'current' => $currentStats['active_users'],
                    'previous' => $previousStats['active_users'] - $currentStats['active_users'],
                ],
                'declining' => [
                    'current' => $currentStats['declining_users'],
                    'previous' => $previousStats['declining_users'] - $currentStats['declining_users'],
                ],
                'watchlist' => [
                    'current' => $currentStats['watchlist_users'],
                    'previous' => $previousStats['watchlist_users'] - $currentStats['watchlist_users'],
                ],
                'suspended' => [
                    'current' => $currentStats['suspended_users'],
                    'previous' => $previousStats['suspended_users'] - $currentStats['suspended_users'],
                ],
                'open_reports' => [
                    'current' => $currentStats['pending_reports'],
                    'previous' => 0, // Calculate delta if needed
                ],
            ];

            // Get status distribution
            $statusDistribution = User::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // Get 30-day trend
            $userTrend = [];
            for ($i = 29; $i >= 0; $i--) {
                $date = now()->subDays($i)->format('Y-m-d');
                $userTrend[] = [
                    'date' => $date,
                    'active' => User::where('status', 'active')
                        ->whereDate('updated_at', '<=', $date)
                        ->count(),
                    'watchlist' => User::where('status', 'watchlist')
                        ->whereDate('updated_at', '<=', $date)
                        ->count(),
                    'suspended' => User::where('status', 'suspended')
                        ->whereDate('updated_at', '<=', $date)
                        ->count(),
                ];
            }

            // Get top alerts
            $topAlerts = Alert::with(['alertable'])
                ->where('status', 'pending')
                ->orderByRaw("FIELD(priority, 'critical', 'high', 'medium', 'low')")
                ->orderBy('created_at', 'asc')
                ->limit(5)
                ->get()
                ->map(function ($alert) {
                    return [
                        'id' => $alert->id,
                        'user_name' => $alert->alertable->name ?? 'Unknown',
                        'priority' => $alert->priority,
                        'message' => $alert->message,
                        'recommended_actions' => is_array($alert->recommended_actions)
                            ? implode(', ', $alert->recommended_actions)
                            : $alert->recommended_actions,
                        'created_at' => $alert->created_at->diffForHumans(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'overview' => $overview,
                    'status_distribution' => $statusDistribution,
                    'user_trend' => $userTrend,
                    'top_alerts' => $topAlerts,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get overview KPI tiles
     */
    private function getOverviewStats($days)
    {
        $startDate = Carbon::now()->subDays($days);

        return [
            'total_users' => User::count(),
            'active_users' => User::where('status', 'active')->count(),
            'declining_users' => User::where('status', 'declining')->count(),
            'watchlist_users' => User::where('status', 'watchlist')->count(),
            'suspended_users' => User::where('status', 'suspended')->count(),
            'dormant_users' => User::where('status', 'inactive')->count(),
            'new_users_period' => User::where('created_at', '>=', $startDate)->count(),
            'pending_reports' => ContentReport::where('status', 'pending')->count(),
            'high_priority_alerts' => Alert::where('status', 'pending')
                ->where('priority', 'high')
                ->count(),
        ];
    }

    /**
     * Get top priority alerts for dashboard
     */
    private function getTopAlerts($limit = 10)
    {
        return Alert::with(['alertable', 'assignedTo:id,name'])
            ->where('status', 'pending')
            ->orderByRaw("FIELD(priority, 'critical', 'high', 'medium', 'low')")
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get()
            ->map(function ($alert) {
                return [
                    'id' => $alert->id,
                    'type' => $alert->alert_type,
                    'priority' => $alert->priority,
                    'message' => $alert->message,
                    'target' => [
                        'type' => class_basename($alert->alertable_type),
                        'id' => $alert->alertable_id,
                        'name' => $alert->alertable->name ?? 'Unknown',
                    ],
                    'recommended_actions' => $alert->recommended_actions,
                    'assigned_to' => $alert->assignedTo?->name,
                    'created_at' => $alert->created_at->diffForHumans(),
                ];
            });
    }

    /**
     * Get user status distribution
     */
    private function getStatusDistribution()
    {
        return User::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                return [
                    'status' => $item->status,
                    'count' => $item->count,
                    'label' => ucfirst($item->status),
                ];
            });
    }

    /**
     * Get activity trend over period
     */
    private function getActivityTrend($days)
    {
        $metrics = UserActivityMetric::selectRaw('
                DATE(updated_at) as date,
                SUM(total_activity_30d) as total_activity,
                AVG(activity_score) as avg_score
            ')
            ->where('updated_at', '>=', Carbon::now()->subDays($days))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $metrics->map(function ($item) {
            return [
                'date' => $item->date,
                'total_activity' => (int) $item->total_activity,
                'avg_score' => round($item->avg_score, 2),
            ];
        });
    }

    /**
     * Get reports summary
     */
    private function getReportsSummary()
    {
        return [
            'pending' => ContentReport::where('status', 'pending')->count(),
            'in_review' => ContentReport::where('status', 'in_review')->count(),
            'resolved' => ContentReport::where('status', 'resolved')
                ->where('reviewed_at', '>=', Carbon::now()->subDays(7))
                ->count(),
            'dismissed' => ContentReport::where('status', 'dismissed')
                ->where('reviewed_at', '>=', Carbon::now()->subDays(7))
                ->count(),
        ];
    }

    // ============================================
    // USER MANAGEMENT
    // ============================================

    /**
     * List all users with metrics and filters
     */
    /**
     * List Users (Admin)
     *
     * Returns a paginated list of users with metrics and filters.
     *
     * @authenticated
     *
     * @queryParam status string Filter by computed status. Example: active
     * @queryParam role string Filter by role. Example: customer
     * @queryParam reports_gte integer Filter by minimum validated reports. Example: 5
     * @queryParam activity_score_min number Filter by minimum activity score. Example: 70
     * @queryParam search string Search by name, email, phone. Example: John
     * @queryParam has_merchants boolean Show only users with merchants. Example: true
     * @queryParam pending_merchants boolean Show only users with pending merchant registrations. Example: true
     * @queryParam has_alerts boolean Show only users with active alerts. Example: true
     * @queryParam per_page integer Number of users per page (default: 15). Example: 10
     * @queryParam page integer Page number for pagination. Example: 1
     *
     * @response 200 {
     *   "data": [ ... ],
     *   "meta": { ... }
     * }
     */
    /**
     * List Users (Admin)
     *
     * Returns a paginated list of users with metrics and filters.
     *
     * @authenticated
     *
     * @queryParam status string Filter by computed status. Example: active
     * @queryParam role string Filter by role. Example: customer
     * @queryParam reports_gte integer Filter by minimum validated reports. Example: 5
     * @queryParam activity_score_min number Filter by minimum activity score. Example: 70
     * @queryParam search string Search by name, email, phone. Example: John
     * @queryParam has_merchants boolean Show only users with merchants. Example: true
     * @queryParam pending_merchants boolean Show only users with pending merchant registrations. Example: true
     * @queryParam has_alerts boolean Show only users with active alerts. Example: true
     * @queryParam per_page integer Number of users per page (default: 15). Example: 10
     * @queryParam page integer Page number for pagination. Example: 1
     *
     * @response 200 {
     *   "data": [ ... ],
     *   "meta": { ... }
     * }
     */
    public function index(Request $request)
    {
        $query = User::with(['roles', 'merchants']);

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('nik', 'like', "%{$search}%")
                    ->orWhereHas('merchants', function ($mq) use ($search) {
                        $mq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by status (changed from status)
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filter by role
        if ($request->filled('role')) {
            if ($request->role === 'customer') {
                // Hanya user yang role-nya customer SAJA
                $query->whereHas('roles', function ($q) {
                    $q->where('name', 'customer');
                })
                ->whereDoesntHave('roles', function ($q) {
                    $q->where('name', '!=', 'customer');
                });
            } else {
                // Role lain: tetap seperti biasa
                $query->whereHas('roles', function ($q) use ($request) {
                    $q->where('name', $request->role);
                });
            }
        }

        // Filter by reports threshold
        if ($request->filled('reports_gte')) {
            $query->whereHas('activityMetric', function ($q) use ($request) {
                $q->where('reports_validated_30d', '>=', $request->reports_gte);
            });
        }

        // Filter by activity score range
        if ($request->filled('activity_score_min')) {
            $query->whereHas('activityMetric', function ($q) use ($request) {
                $q->where('activity_score', '>=', $request->activity_score_min);
            });
        }

        // Filter: Show only users with merchants
        if ($request->boolean('has_merchants')) {
            $query->whereHas('merchants');
        }

        // Filter: Show only users with pending merchant registrations
        if ($request->boolean('pending_merchants')) {
            $query->whereHas('merchants', function ($q) {
                $q->where('status', 'pending');
            });
        }

        // ✅ FIX: Filter by active alerts
        if ($request->filled('has_alerts') || $request->boolean('has_alerts')) {
            $query->whereHas('alerts', function ($q) {
                $q->where('status', 'pending');
            });
        }

        $users = $query->latest()
            ->paginate($request->input('per_page', 15));

        // Transform for UI
        $users->getCollection()->transform(function ($user) {
            $metric = $user->activityMetric;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'nik' => $user->nik,
                'status' => $user->status,
                'status' => $user->status ?? 'active',
                'roles' => $user->roles->pluck('name'),
                'merchants' => $user->merchants->map(function ($m) {
                    return [
                        'id' => $m->id,
                        'name' => $m->name,
                        'status' => $m->status,
                    ];
                }),
                'activity_score' => $metric ? $metric->activity_score : 0,
                'reports_30d' => $metric ? $metric->reports_validated_30d : 0,
                'last_login' => $user->last_login_at,
                'alerts_count' => $user->alerts()->where('status', 'pending')->count(),
                'created_at' => $user->created_at,
            ];
        });

        return response()->json($users);
    }

    /**
     * Create User (Admin)
     *
     * Create a new user and assign default role 'customer'.
     *
     * @authenticated
     *
     * @bodyParam name string required User name. Example: John Doe
     * @bodyParam email string required User email. Example: john@example.com
     * @bodyParam phone string User phone. Example: 08123456789
     * @bodyParam nik string required User NIK (16 digits). Example: 1234567890123456
     * @bodyParam password string required Password. Example: Password123!
     * @bodyParam password_confirmation string required Password confirmation. Example: Password123!
     *
     * @response 201 {
     *   "message": "User berhasil dibuat.",
     *   "data": { ... }
     * }
     * @response 422 {
     *   "message": "Data yang diberikan tidak valid.",
     *   "errors": { ... }
     * }
     */
    /**
     * Create User (Admin)
     *
     * Create a new user and assign default role 'customer'.
     *
     * @authenticated
     *
     * @bodyParam name string required User name. Example: John Doe
     * @bodyParam email string required User email. Example: john@example.com
     * @bodyParam phone string User phone. Example: 08123456789
     * @bodyParam nik string required User NIK (16 digits). Example: 1234567890123456
     * @bodyParam password string required Password. Example: Password123!
     * @bodyParam password_confirmation string required Password confirmation. Example: Password123!
     *
     * @response 201 {
     *   "message": "User berhasil dibuat.",
     *   "data": { ... }
     * }
     * @response 422 {
     *   "message": "Data yang diberikan tidak valid.",
     *   "errors": { ... }
     * }
     */
    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'phone' => ['nullable', 'string', 'max:13'],
                'nik' => ['required', 'string', 'size:16', 'unique:users,nik'],
                'password' => [
                    'required',
                    'confirmed',
                    \Illuminate\Validation\Rules\Password::min(8),
                    'regex:/^(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*\-_]).+$/',
                ],
                'password_confirmation' => ['required'],
            ],
            [
                'name.required' => 'Nama wajib diisi.',
                'email.required' => 'Email wajib diisi.',
                'email.email' => 'Format email tidak valid.',
                'email.unique' => 'Email sudah terdaftar.',
                'password.required' => 'Password wajib diisi.',
                'password.confirmed' => 'Konfirmasi password tidak cocok.',
                'password_confirmation.required' => 'Konfirmasi password wajib diisi.',
                'password_confirmation.confirmed' => 'Konfirmasi password tidak cocok.',
                'password.min' => 'Password minimal 8 karakter.',
                'password.regex' => 'Password harus mengandung huruf besar, angka, dan simbol (!@#$%^&*-_).',
                'nik.size' => 'NIK harus 16 karakter.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Data yang diberikan tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $user = \App\Models\User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'nik' => $data['nik'],
            'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
            'status' => 'active',
        ]);

        // Tetapkan role default 'customer'
        $role = \App\Models\Role::firstOrCreate(['name' => 'customer']);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'data' => $user,
        ], 201);
    }
    /**
     * Get user detail with full context
     */
    /**
     * Get User Detail (Admin)
     *
     * Get detail of a user by ID, including merchants, metrics, status history, actions, reports, alerts, and permissions.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the user. Example: 1
     *
     * @response 200 {
     *   "data": {
     *     "user": { ... },
     *     "status_history": [ ... ],
     *     "admin_actions": [ ... ],
     *     "reports": [ ... ],
     *     "alerts": [ ... ],
     *     "permissions": { ... }
     *   }
     * }
     * @response 404 {
     *   "message": "User not found"
     * }
     */
    /**
     * Get User Detail (Admin)
     *
     * Get detail of a user by ID, including merchants, metrics, status history, actions, reports, alerts, and permissions.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the user. Example: 1
     *
     * @response 200 {
     *   "data": {
     *     "user": { ... },
     *     "status_history": [ ... ],
     *     "admin_actions": [ ... ],
     *     "reports": [ ... ],
     *     "alerts": [ ... ],
     *     "permissions": { ... }
     *   }
     * }
     * @response 404 {
     *   "message": "User not found"
     * }
     */
    public function show(string $id)
    {
        $user = User::with([
            'roles',
            'merchants.segmentation',
            'merchants.products',
            'activityMetric',
        ])
            ->withCount(['merchants', 'communityPosts', 'postComments'])
            ->findOrFail($id);

        // Get status history (from admin_actions)
        $statusHistory = AdminAction::where('target_type', User::class)
            ->where('target_id', $user->id)
            ->where('action_type', 'status_change')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($action) {
                return [
                    'from' => $action->status_before,
                    'to' => $action->status_after,
                    'reason' => $action->reason,
                    'admin' => $action->admin->name ?? 'System',
                    'created_at' => $action->created_at,
                ];
            });

        // Get admin actions history
        $adminActions = AdminAction::with('admin:id,name')
            ->where('target_type', User::class)
            ->where('target_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        // Get related reports
        $reports = ContentReport::with(['reason', 'reviewer:id,name'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id) // as reporter
                    ->orWhere(function ($q2) use ($user) {
                        $q2->where('reportable_type', User::class)
                            ->where('reportable_id', $user->id); // as reported
                    });
            })
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Get active alerts
        $alerts = Alert::where('alertable_type', User::class)
            ->where('alertable_id', $user->id)
            ->where('status', 'pending')
            ->get();

        return response()->json([
            'user' => $user->makeVisible(['status']), 
            'status_history' => $statusHistory,
            'admin_actions' => $adminActions,
            'reports' => $reports,
            'alerts' => $alerts,
            'permissions' => $this->getUserPermissions($user),
        ]);
    }

    /**
     * Manual status change with reason
     */
    /**
     * Change User Status (Admin)
     *
     * Manually change user status with reason.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the user. Example: 1
     * @bodyParam status string required New status. Example: suspended
     * @bodyParam reason string required Reason for status change. Example: Melanggar aturan
     *
     * @response 200 {
     *   "message": "User status updated successfully",
     *   "data": { ... }
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    /**
     * Change User Status (Admin)
     *
     * Manually change user status with reason.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the user. Example: 1
     * @bodyParam status string required New status. Example: suspended
     * @bodyParam reason string required Reason for status change. Example: Melanggar aturan
     *
     * @response 200 {
     *   "message": "User status updated successfully",
     *   "data": { ... }
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    public function changeStatus(ChangeUserStatusRequest $request, string $id)
    {
        $user = User::findOrFail($id);
        $validated = $request->validated();

        $oldStatus = $user->status; 
        $newStatus = $validated['status'];

        DB::transaction(function () use ($user, $request, $oldStatus, $newStatus) {
            // Update user status
            $user->update([
                'status' => $newStatus, 
            ]);

            // Log action
            AdminAction::create([
                'admin_id' => auth()->id(),
                'action_type' => 'status_change',
                'target_type' => User::class,
                'target_id' => $user->id,
                'reason' => $validated['reason'] ?? null,
                'metadata' => [
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ],
                'status_before' => $oldStatus,
                'status_after' => $newStatus,
            ]);

            // Clear related alerts
            Alert::where('alertable_type', User::class)
                ->where('alertable_id', $user->id)
                ->where('status', 'pending')
                ->update(['status' => 'resolved', 'resolved_at' => now()]);
        });

        return response()->json([
            'message' => 'User status updated successfully',
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Warn user (send notification + log)
     */
    /**
     * Warn User (Admin)
     *
     * Send warning notification to user and log action.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the user. Example: 1
     * @bodyParam reason string required Reason for warning. Example: Melanggar aturan
     * @bodyParam message string required Warning message. Example: Anda melanggar aturan komunitas
     *
     * @response 200 {
     *   "message": "Warning sent successfully"
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    /**
     * Warn User (Admin)
     *
     * Send warning notification to user and log action.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the user. Example: 1
     * @bodyParam reason string required Reason for warning. Example: Melanggar aturan
     * @bodyParam message string required Warning message. Example: Anda melanggar aturan komunitas
     *
     * @response 200 {
     *   "message": "Warning sent successfully"
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    public function warn(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
            'message' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::findOrFail($id);

        DB::transaction(function () use ($user, $request) {
            // Log action
            AdminAction::create([
                'admin_id' => Auth::id(),
                'action_type' => 'warn_user',
                'target_type' => User::class,
                'target_id' => $user->id,
                'reason' => $request->reason,
                'metadata' => [
                    'message' => $request->message,
                    'timestamp' => now(),
                ],
            ]);

            // TODO: Send notification to user
            // $user->notify(new UserWarningNotification($request->message));
        });

        return response()->json([
            'message' => 'Warning sent successfully',
        ]);
    }

    /**
     * Suspend user
     */
    /**
     * Suspend User (Admin)
     *
     * Suspend a user with reason and optional duration.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the user. Example: 1
     * @bodyParam reason string required Reason for suspension. Example: Melanggar aturan
     * @bodyParam duration_days integer Duration in days. Example: 30
     *
     * @response 200 {
     *   "message": "User suspended successfully",
     *   "data": { ... }
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    /**
     * Suspend User (Admin)
     *
     * Suspend a user with reason and optional duration.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the user. Example: 1
     * @bodyParam reason string required Reason for suspension. Example: Melanggar aturan
     * @bodyParam duration_days integer Duration in days. Example: 30
     *
     * @response 200 {
     *   "message": "User suspended successfully",
     *   "data": { ... }
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    public function suspend(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
            'duration_days' => 'nullable|integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::findOrFail($id);
        $oldStatus = $user->status; 

        $user->update([
            'status' => 'suspended', 
        ]);

        // Log action
        AdminAction::create([
            'admin_id' => Auth::id(),
            'action_type' => 'suspend_user',
            'target_type' => User::class,
            'target_id' => $user->id,
            'reason' => $request->reason,
            'status_before' => $oldStatus,
            'status_after' => 'suspended',
            'metadata' => [
                'duration_days' => $request->duration_days,
                'expires_at' => $request->duration_days
                    ? now()->addDays($request->duration_days)
                    : null,
            ],
        ]);

        // TODO: Revoke user's active tokens/sessions
        // $user->tokens()->delete();

        return response()->json([
            'message' => 'User suspended successfully',
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Unsuspend user
     */
    /**
     * Unsuspend User (Admin)
     *
     * Unsuspend a user and log action.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the user. Example: 1
     * @bodyParam reason string required Reason for unsuspend. Example: Masa suspend selesai
     *
     * @response 200 {
     *   "message": "User unsuspended successfully",
     *   "data": { ... }
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    /**
     * Unsuspend User (Admin)
     *
     * Unsuspend a user and log action.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the user. Example: 1
     * @bodyParam reason string required Reason for unsuspend. Example: Masa suspend selesai
     *
     * @response 200 {
     *   "message": "User unsuspended successfully",
     *   "data": { ... }
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    public function unsuspend(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::findOrFail($id);

        $user->update([
            'status' => 'active', 
        ]);

        // Log action
        AdminAction::create([
            'admin_id' => Auth::id(),
            'action_type' => 'unsuspend_user',
            'target_type' => User::class,
            'target_id' => $user->id,
            'reason' => $request->reason,
            'status_before' => 'suspended',
            'status_after' => 'active',
        ]);

        return response()->json([
            'message' => 'User unsuspended successfully',
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Send notification to user
     */
    /**
     * Send Notification to User (Admin)
     *
     * Send notification to user and log action.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the user. Example: 1
     * @bodyParam type string required Notification type. Example: outreach
     * @bodyParam message string required Notification message. Example: Selamat, Anda terpilih!
     * @bodyParam metadata array Additional metadata. Example: {"promo_code": "ABC123"}
     *
     * @response 200 {
     *   "message": "Notification sent successfully"
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    /**
     * Send Notification to User (Admin)
     *
     * Send notification to user and log action.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the user. Example: 1
     * @bodyParam type string required Notification type. Example: outreach
     * @bodyParam message string required Notification message. Example: Selamat, Anda terpilih!
     * @bodyParam metadata array Additional metadata. Example: {"promo_code": "ABC123"}
     *
     * @response 200 {
     *   "message": "Notification sent successfully"
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    public function notify(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:outreach,event_invite,paguyuban_invite,voucher,general',
            'message' => 'required|string|max:1000',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::findOrFail($id);

        // Log action
        AdminAction::create([
            'admin_id' => Auth::id(),
            'action_type' => 'send_notification',
            'target_type' => User::class,
            'target_id' => $user->id,
            'reason' => "Notification type: {$request->type}",
            'metadata' => array_merge([
                'type' => $request->type,
                'message' => $request->message,
            ], $request->metadata ?? []),
        ]);

        // TODO: Send actual notification
        // $user->notify(new AdminNotification($request->type, $request->message));

        return response()->json([
            'message' => 'Notification sent successfully',
        ]);
    }

    /**
     * Bulk update user status
     */
    /**
     * Bulk Update User Status (Admin)
     *
     * Bulk update status for multiple users.
     *
     * @authenticated
     *
     * @bodyParam user_ids array required Array of user IDs. Example: [1,2,3]
     * @bodyParam status string required New status. Example: suspended
     * @bodyParam reason string required Reason for status change. Example: Melanggar aturan
     *
     * @response 200 {
     *   "message": "Bulk update completed successfully",
     *   "updated_count": 3
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    /**
     * Bulk Update User Status (Admin)
     *
     * Bulk update status for multiple users.
     *
     * @authenticated
     *
     * @bodyParam user_ids array required Array of user IDs. Example: [1,2,3]
     * @bodyParam status string required New status. Example: suspended
     * @bodyParam reason string required Reason for status change. Example: Melanggar aturan
     *
     * @response 200 {
     *   "message": "Bulk update completed successfully",
     *   "updated_count": 3
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    public function bulkUpdateStatus(BulkUpdateStatusRequest $request)
    {
        $validated = $request->validated();
        $userIds = $validated['user_ids'];
        $newStatus = $validated['status'];

        $users = User::whereIn('id', $userIds)->get();

        DB::transaction(function () use ($users, $newStatus) {
            foreach ($users as $user) {
                $oldStatus = $user->status; 

                $user->update([
                    'status' => $newStatus, 
                ]);

                AdminAction::create([
                    'admin_id' => Auth::id(),
                    'action_type' => 'bulk_update',
                    'target_type' => User::class,
                    'target_id' => $user->id,
                    'reason' => $request->reason,
                    'status_before' => $oldStatus,
                    'status_after' => $newStatus,
                ]);
            }
        });

        return response()->json([
            'message' => 'Bulk update completed successfully',
            'updated_count' => count($userIds),
        ]);
    }

    // ============================================
    // ALERTS MANAGEMENT
    // ============================================

    /**
     * Get alerts queue
     */
    /**
     * List Alerts (Admin)
     *
     * Returns a paginated list of alerts with filters.
     *
     * @authenticated
     *
     * @queryParam status string Filter by alert status. Example: pending
     * @queryParam priority string Filter by priority. Example: high
     * @queryParam alert_type string Filter by alert type. Example: report
     * @queryParam assigned_to_me boolean Only alerts assigned to me. Example: true
     * @queryParam per_page integer Number of alerts per page (default: 15). Example: 10
     * @queryParam page integer Page number for pagination. Example: 1
     *
     * @response 200 {
     *   "data": [ ... ],
     *   "meta": { ... }
     * }
     */
    /**
     * List Alerts (Admin)
     *
     * Returns a paginated list of alerts with filters.
     *
     * @authenticated
     *
     * @queryParam status string Filter by alert status. Example: pending
     * @queryParam priority string Filter by priority. Example: high
     * @queryParam alert_type string Filter by alert type. Example: report
     * @queryParam assigned_to_me boolean Only alerts assigned to me. Example: true
     * @queryParam per_page integer Number of alerts per page (default: 15). Example: 10
     * @queryParam page integer Page number for pagination. Example: 1
     *
     * @response 200 {
     *   "data": [ ... ],
     *   "meta": { ... }
     * }
     */
    public function alerts(Request $request)
    {
        $query = Alert::with(['alertable', 'assignedTo:id,name']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by priority
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        // Filter by type
        if ($request->has('alert_type')) {
            $query->where('alert_type', $request->alert_type);
        }

        // Only assigned to me
        if ($request->boolean('assigned_to_me')) {
            $query->where('assigned_to', Auth::id());
        }

        $alerts = $query->orderByRaw("FIELD(priority, 'critical', 'high', 'medium', 'low')")
            ->orderBy('created_at', 'asc')
            ->paginate($request->input('per_page', 15));

        return response()->json($alerts);
    }

    /**
     * Execute alert action
     */
    /**
     * Execute Alert Action (Admin)
     *
     * Execute action for a specific alert.
     *
     * @authenticated
     *
     * @urlParam alertId integer required The ID of the alert. Example: 1
     * @bodyParam action string required Action to execute. Example: warn
     * @bodyParam reason string required Reason for action. Example: Melanggar aturan
     *
     * @response 200 {
     *   "message": "Alert action executed successfully"
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    /**
     * Execute Alert Action (Admin)
     *
     * Execute action for a specific alert.
     *
     * @authenticated
     *
     * @urlParam alertId integer required The ID of the alert. Example: 1
     * @bodyParam action string required Action to execute. Example: warn
     * @bodyParam reason string required Reason for action. Example: Melanggar aturan
     *
     * @response 200 {
     *   "message": "Alert action executed successfully"
     * }
     * @response 422 {
     *   "errors": { ... }
     * }
     */
    public function executeAlertAction(Request $request, $alertId)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:warn,suspend,limit_posting,invite_event,invite_paguyuban,dismiss',
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $alert = Alert::findOrFail($alertId);

        DB::transaction(function () use ($alert, $request) {
            // Execute action based on type
            switch ($request->action) {
                case 'warn':
                    $this->warn($request, $alert->alertable_id);
                    break;
                case 'suspend':
                    $this->suspend($request, $alert->alertable_id);
                    break;
                    // Add other actions...
            }

            // Mark alert as resolved
            $alert->update([
                'status' => $request->action === 'dismiss' ? 'dismissed' : 'resolved',
                'resolved_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Alert action executed successfully',
        ]);
    }

    // ============================================
    // UTILITY METHODS
    // ============================================

    /**
     * Check if user has active alerts
     */
    private function hasActiveAlerts($userId)
    {
        return Alert::where('alertable_type', User::class)
            ->where('alertable_id', $userId)
            ->where('status', 'pending')
            ->exists();
    }

    /**
     * Get user permissions (what actions admin can do)
     */
    private function getUserPermissions($user)
    {
        return [
            'can_warn' => true,
            'can_suspend' => $user->status !== 'suspended',
            'can_unsuspend' => $user->status === 'suspended',
            'can_change_status' => true,
            'can_delete' => !$user->merchants()->where('status', 'approved')->exists(),
        ];
    }

    /**
     * Get user management overview stats
     */
    public function overviewStats(Request $request)
    {
        try {
            $period = $request->input('period', 'last_30_days');
            $days = $this->getPeriodDays($period);
            $startDate = Carbon::now()->subDays($days);

            // Current period stats
            $currentStats = [
                'total_users' => [
                    'current' => User::whereHas('roles', fn($q) => $q->whereIn('name', ['customer', 'umkm-owner']))
                        ->count(),
                    'previous' => User::whereHas('roles', fn($q) => $q->whereIn('name', ['customer', 'umkm-owner']))
                        ->where('created_at', '<', $startDate)
                        ->count(),
                ],
                'active_customers' => [
                    'current' => User::whereHas('roles', fn($q) => $q->where('name', 'customer'))
                        ->where('status', 'active')
                        ->where('created_at', '>=', $startDate)
                        ->count(),
                    'previous' => User::whereHas('roles', fn($q) => $q->where('name', 'customer'))
                        ->where('status', 'active')
                        ->where('created_at', '<', $startDate)
                        ->count(),
                ],
                'pending_merchants' => [
                    'current' => Merchant::where('status', 'pending')
                        ->where('created_at', '>=', $startDate)
                        ->count(),
                    'previous' => Merchant::where('status', 'pending')
                        ->where('created_at', '<', $startDate)
                        ->count(),
                ],
                'approved_merchants' => [
                    'current' => Merchant::where('status', 'approved')
                        ->where('created_at', '>=', $startDate)
                        ->count(),
                    'previous' => Merchant::where('status', 'approved')
                        ->where('created_at', '<', $startDate)
                        ->count(),
                ],
                'suspended_users' => [
                    'current' => User::where('status', 'suspended')
                        ->where('updated_at', '>=', $startDate)
                        ->count(),
                    'previous' => User::where('status', 'suspended')
                        ->where('updated_at', '<', $startDate)
                        ->count(),
                ],
                'watchlist_users' => [
                    'current' => User::where('status', 'watchlist')
                        ->where('updated_at', '>=', $startDate)
                        ->count(),
                    'previous' => User::where('status', 'watchlist')
                        ->where('updated_at', '<', $startDate)
                        ->count(),
                ],
            ];

            // Status distribution (changed from status)
            $statusDistribution = User::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get()
                ->keyBy('status')
                ->map->count;

            return response()->json([
                'success' => true,
                'data' => [
                    'overview' => $currentStats,
                    'status_distribution' => $statusDistribution,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load overview statistics',
            ], 500);
        }
    }

    private function getPeriodDays($period)
    {
        return match ($period) {
            'last_7_days' => 7,
            'last_30_days' => 30,
            'last_90_days' => 90,
            'all_time' => 36500, // ~100 years
            default => 30,
        };
    }

    /**
     * Get user login trend (daily logins over past X days)
     */
    public function loginTrend(Request $request, $id)
    {
        $days = (int) $request->query('days', 30);
        $days = max(1, min($days, 90));

        User::findOrFail($id);

        $start = now()->subDays($days - 1)->startOfDay();

        $rows = DB::table('user_login_events')
            ->selectRaw('DATE(logged_in_at) as date, COUNT(*) as total')
            ->where('user_id', $id)
            ->where('logged_in_at', '>=', $start)
            ->groupByRaw('DATE(logged_in_at)')
            ->orderBy('date')
            ->get();

        $map = $rows->keyBy('date');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $d = now()->subDays($days - 1 - $i)->toDateString();
            $series[] = [
                'date' => $d,
                'total' => (int) ($map[$d]->total ?? 0),
            ];
        }

        return response()->json([
            'data' => [
                'days' => $days,
                'series' => $series,
            ],
        ]);
    }
}
