<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Merchant;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminUserController extends Controller
{
    /**
     * List all users with filters
     */
    public function index(Request $request)
    {
        $query = User::with('roles:id,name', 'merchants:id,name,status');

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('nik', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->has('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $users = $query->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json($users);
    }

    /**
     * Get single user detail
     */
    public function show($id)
    {
        $user = User::with([
            'roles:id,name',
            'merchants' => function ($query) {
                $query->with('segmentation:id,name');
            }
        ])->findOrFail($id);

        return response()->json(['data' => $user]);
    }

    /**
     * Create new user (offline registration by admin)
     * Can also register as merchant directly
     */
    public function store(Request $request)
    {
        Log::info('[AdminUserController] Store called', [
            'data' => $request->except(['password', 'merchant.logo'])
        ]);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'nik' => 'required|string|size:16|unique:users,nik',
            'password' => 'required|string|min:8',
            'role' => 'required|in:customer,umkm-owner,admin',
            'register_as_merchant' => 'boolean',
            'merchant' => 'required_if:register_as_merchant,true|array',
            'merchant.name' => 'required_with:merchant|string|max:255',
            'merchant.phone' => 'required_with:merchant|string|max:20',
            'merchant.segmentation_id' => 'required_with:merchant|exists:segmentations,id',
            'merchant.description' => 'nullable|string',
            'merchant.logo' => 'nullable|image|max:2048',
            'merchant.address' => 'required_with:merchant|array',
            'merchant.address.province_id' => 'required_with:merchant.address',
            'merchant.address.city_id' => 'required_with:merchant.address',
            'merchant.address.district_id' => 'required_with:merchant.address',
            'merchant.address.village_id' => 'required_with:merchant.address',
            'merchant.address.detail' => 'nullable|string',
            'merchant.address.latitude' => 'nullable|numeric',
            'merchant.address.longitude' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'nik' => $request->nik,
                'password' => Hash::make($request->password),
                'status' => 'active',
                'email_verified_at' => now(), 
            ]);

            Log::info('[AdminUserController] User created', ['user_id' => $user->id]);

            $role = Role::where('name', $request->role)->first();
            if ($role) {
                $user->roles()->attach($role->id);
            }

            $merchant = null;

            if ($request->boolean('register_as_merchant')) {
                $merchantData = $request->merchant;

                // Handle logo upload
                $logoPath = null;
                if ($request->hasFile('merchant.logo')) {
                    $logoPath = $request->file('merchant.logo')
                        ->store('merchants/logos', 'public');
                }

                $merchant = Merchant::create([
                    'user_id' => $user->id,
                    'name' => $merchantData['name'],
                    'phone' => $merchantData['phone'],
                    'segmentation_id' => $merchantData['segmentation_id'],
                    'description' => $merchantData['description'] ?? null,
                    'logo_path' => $logoPath,
                    'status' => 'approved', 
                    'response_at' => now(),
                    'response_by' => Auth::id(),
                ]);

                Log::info('[AdminUserController] Merchant created', [
                    'merchant_id' => $merchant->id
                ]);

                $addr = $merchantData['address'];
                $merchant->addresses()->create([
                    'province_id' => $addr['province_id'],
                    'city_id' => $addr['city_id'],
                    'district_id' => $addr['district_id'],
                    'village_id' => $addr['village_id'],
                    'detail' => $addr['detail'] ?? null,
                    'label' => 'utama',
                    'latitude' => $addr['latitude'] ?? null,
                    'longitude' => $addr['longitude'] ?? null,
                ]);

                if ($request->role !== 'umkm-owner') {
                    $umkmRole = Role::where('name', 'umkm-owner')->first();
                    if ($umkmRole) {
                        $user->roles()->syncWithoutDetaching([$umkmRole->id]);
                    }
                }
            }

            DB::commit();

            Log::info('[AdminUserController] Transaction committed successfully');

            return response()->json([
                'message' => 'User created successfully',
                'data' => [
                    'user' => $user->fresh()->load('roles'),
                    'merchant' => $merchant ? $merchant->fresh()->load('segmentation', 'primaryAddress') : null,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[AdminUserController] Failed to create user', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to create user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle user status (active/inactive)
     */
    public function toggleStatus($id)
    {
        $user = User::findOrFail($id);
        // Prevent admin from deactivating themselves
        if ($user->id === Auth::id()) {
            return response()->json([
                'message' => 'You cannot deactivate your own account',
            ], 422);
        }


        $newStatus = $user->status === 'active' ? 'inactive' : 'active';
        $user->update(['status' => $newStatus]);

        return response()->json([
            'message' => "User status changed to {$newStatus}",
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Delete user
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        // Prevent admin from deleting themselves
        if ($user->id === Auth::id()) {
            return response()->json([
                'message' => 'You cannot delete your own account',
            ], 422);
        }

        // Check if user has merchants
        if ($user->merchants()->exists()) {
            return response()->json([
                'message' => 'Cannot delete user with existing merchants. Please delete merchants first.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully',
        ]);
    }
}
