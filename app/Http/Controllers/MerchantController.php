<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MerchantController extends Controller
{
    /**
     * ✅ NEW: Public endpoint untuk list merchants
     * Menampilkan merchant yang sudah approved
     */
    // public function publicIndex(Request $request)
    // {
    //     $perPage = $request->input('per_page', 12);
    //     $search = $request->input('search');
    //     $segmentationId = $request->input('segmentation_id');
    //     $cityId = $request->input('city_id');
    //     $random = $request->boolean('random', false); // Default false

    //     $query = Merchant::with([
    //         'segmentation:id,name',
    //         'primaryAddress', // ✅ Load full address relation
    //         'primaryAddress.province:id,name',
    //         'primaryAddress.city:id,name', // ✅ Ini akan load dari Regency
    //         'primaryAddress.district:id,name',
    //     ])
    //         ->where('status', 'approved')
    //         ->withCount('products'); // Hitung jumlah produk

    //     // Filter by search (nama merchant)
    //     if ($search) {
    //         $query->where('name', 'like', "%{$search}%");
    //     }

    //     // Filter by segmentation
    //     if ($segmentationId) {
    //         $query->where('segmentation_id', $segmentationId);
    //     }

    //     // Filter by city
    //     if ($cityId) {
    //         $query->whereHas('primaryAddress', function ($q) use ($cityId) {
    //             $q->where('city_id', $cityId);
    //         });
    //     }

    //     // ✅ Random order jika diminta
    //     if ($random) {
    //         $query->inRandomOrder();
    //     } else {
    //         $query->latest(); // Default: newest first
    //     }

    //     $merchants = $query->paginate($perPage);

    //     return response()->json($merchants);
    // }

    /**
     * Public endpoint untuk random merchants
     * Khusus untuk homepage/recommendation
     */
    public function publicRandom(Request $request)
    {
        $limit = $request->input('limit', 8);
        $segmentationId = $request->input('segmentation_id');

        try {
            $query = Merchant::query()
                ->where('status', 'approved')
                ->withCount([
                    'products' => function ($query) {
                        $query->where('status', 'published');
                    }
                ]);

            // Filter by segmentation jika ada
            if ($segmentationId) {
                $query->where('segmentation_id', $segmentationId);
            }

            $merchants = $query->inRandomOrder()
                ->limit($limit)
                ->get();

            // ✅ Manual load relations untuk avoid nested eager loading issues
            $merchants->load([
                'segmentation',
                'primaryAddress.province',
                'primaryAddress.city',
                'primaryAddress.district',
            ]);

            return response()->json([
                'data' => $merchants,
                'total' => $merchants->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error in publicRandom: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'message' => 'Failed to fetch merchants',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Public endpoint untuk show single merchant
     */
    public function publicShow(Request $request, $slugOrId)
    {
        // Cari berdasarkan slug atau id
        $merchant = Merchant::with([
            'segmentation:id,name',
            'primaryAddress',
            'primaryAddress.province:id,name',
            'primaryAddress.city:id,name',
            'primaryAddress.district:id,name',
            'primaryAddress.village:id,name',
        ])
            ->where('status', 'approved')
            ->where(function ($q) use ($slugOrId) {
                $q->where('id', $slugOrId)
                    ->orWhere('slug', $slugOrId); 
            })
            ->withCount('products')
            ->firstOrFail();

        return response()->json([
            'data' => $merchant,
        ]);
    }

    // Customer mendaftar UMKM -> status pending
    public function register(Request $request)
    {
        $user = $request->user();

        // Wajib punya role "customer"
        $hasCustomerRole = $user->roles()->whereRaw('LOWER(name) = ?', ['customer'])->exists();
        if (!$hasCustomerRole) {
            return response()->json([
                'message' => 'Akses ditolak. Hanya pengguna dengan role customer yang dapat mendaftar UMKM.',
            ], 403);
        }

        $already = Merchant::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending'])
            ->exists();
        if ($already) {
            return response()->json([
                'message' => 'Anda sudah memiliki pendaftaran UMKM yang menunggu.',
            ], 422);
        }

        $validator = Validator::make(
            $request->all(),
            [
                'name' => ['required', 'string', 'max:255'],
                'phone' => ['required', 'string', 'min:8', 'max:20', 'regex:/^[0-9+\-()\s]+$/'],
                'description' => ['nullable', 'string'],
                'segmentation_id' => ['required', 'integer', Rule::exists('segmentations', 'id')],
                'address.province_id' => ['required', 'integer', Rule::exists('provinces', 'id')],
                'address.city_id' => ['required', 'integer', Rule::exists('cities', 'id')],
                'address.district_id' => ['required', 'integer', Rule::exists('districts', 'id')],
                'address.village_id' => ['required', 'integer', Rule::exists('villages', 'id')],
                'address.detail' => ['nullable', 'string', 'max:500'],
                'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            ],
            [
                'name.required' => 'Nama usaha wajib diisi.',
                'phone.required' => 'Nomor telepon wajib diisi.',
                'phone.regex' => 'Format nomor telepon tidak valid.',
                'segmentation_id.required' => 'Segmentasi wajib dipilih.',
                'segmentation_id.exists' => 'Segmentasi tidak ditemukan.',
                'address.province_id.required' => 'Provinsi wajib dipilih.',
                'address.province_id.exists' => 'Provinsi tidak valid.',
                'address.city_id.required' => 'Kab/Kota wajib dipilih.',
                'address.city_id.exists' => 'Kab/Kota tidak valid.',
                'address.district_id.required' => 'Kecamatan wajib dipilih.',
                'address.district_id.exists' => 'Kecamatan tidak valid.',
                'address.village_id.required' => 'Desa/Kelurahan wajib dipilih.',
                'address.village_id.exists' => 'Desa/Kelurahan tidak valid.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Data yang diberikan tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $merchant = DB::transaction(function () use ($validated, $user) {
            $merchant = Merchant::create([
                'user_id' => $user->id,
                'paguyuban_id' => null,
                'segmentation_id' => $validated['segmentation_id'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'phone' => $validated['phone'],
                'logo_path' => null,
                // 'status' default 'pending' dari migration
            ]);

            $addr = $validated['address'];
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

            return $merchant;
        });

        return response()->json([
            'message' => 'Pendaftaran UMKM berhasil dikirim. Menunggu persetujuan admin.',
            'merchant' => $merchant->load(['segmentation', 'primaryAddress']),
        ], 201);
    }

    // Admin menyetujui pendaftaran -> status approved + beri role "umkm-owner"
    public function approve(Request $request, Merchant $merchant)
    {
        // Validasi role admin
        $admin = $request->user();
        $isAdmin = $admin->roles()->whereRaw('LOWER(name) = ?', ['admin'])->exists();
        if (!$isAdmin) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if ($merchant->status === 'approved') {
            return response()->json(['message' => 'Merchant sudah disetujui.'], 422);
        }
        if ($merchant->status === 'rejected') {
            return response()->json(['message' => 'Merchant sudah ditolak.'], 422);
        }

        DB::transaction(function () use ($merchant) {
            // Ensure slug exists (safety check)
            if (empty($merchant->slug)) {
                $merchant->slug = Merchant::generateUniqueSlug($merchant->name);
            }

            $merchant->update([
                'status' => 'approved',
                'response_at' => Carbon::now(),
            ]);

            // Beri role "umkm-owner"
            $owner = $merchant->user;
            if ($owner) {
                $roleId = DB::table('roles')->where('name', 'umkm-owner')->value('id');
                if (!$roleId) {
                    $roleId = DB::table('roles')->insertGetId([
                        'name' => 'umkm-owner',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Use firstOrCreate untuk avoid duplicate entry
                DB::table('role_user')->updateOrInsert(
                    ['user_id' => $owner->id, 'role_id' => $roleId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        });

        return response()->json([
            'message' => 'Merchant disetujui dan slug telah digenerate.',
            'merchant' => $merchant->fresh()->load(['segmentation', 'primaryAddress']),
        ]);
    }

    // Admin menolak pendaftaran -> status rejected
    public function reject(Request $request, Merchant $merchant)
    {
        $admin = $request->user();
        $isAdmin = $admin->roles()->whereRaw('LOWER(name) = ?', ['admin'])->exists();
        if (!$isAdmin) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if ($merchant->status === 'approved') {
            return response()->json(['message' => 'Merchant sudah disetujui, tidak bisa ditolak.'], 422);
        }
        if ($merchant->status === 'rejected') {
            return response()->json(['message' => 'Merchant sudah ditolak.'], 422);
        }

        $merchant->update([
            'status' => 'rejected',
            'response_at' => Carbon::now(),
        ]);

        return response()->json([
            'message' => 'Merchant ditolak.',
            'merchant' => $merchant->fresh()->load(['segmentation', 'primaryAddress']),
        ]);
    }

    /**
     * ADMIN: List all merchants with filters
     */
    public function adminIndex(Request $request)
    {
        Log::info('[MerchantController] adminIndex called', [
            'params' => $request->all()
        ]);

        $query = Merchant::with([
            'user:id,name,email,phone',
            'segmentation:id,name',
            'primaryAddress',
        ])
            ->withCount('products');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by segmentation
        if ($request->has('segmentation_id')) {
            $query->where('segmentation_id', $request->segmentation_id);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($qu) use ($search) {
                        $qu->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $merchants = $query->latest()
            ->paginate($request->input('per_page', 15));

        Log::info('[MerchantController] Returning merchants', [
            'count' => $merchants->count(),
            'total' => $merchants->total()
        ]);

        return response()->json($merchants);
    }

    /**
     * ADMIN: Get single merchant detail
     */
    public function adminShow($id)
    {
        $merchant = Merchant::with([
            'user.roles',
            'segmentation',
            'addresses',
            'products' => function ($query) {
                $query->latest()->limit(10);
            },
            'vouchers',
            'events',
        ])
            ->withCount(['products', 'vouchers'])
            ->findOrFail($id);

        return response()->json(['data' => $merchant]);
    }
}
