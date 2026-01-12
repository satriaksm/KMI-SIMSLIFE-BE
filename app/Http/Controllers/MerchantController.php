<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Merchant;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MerchantController extends Controller
{

    public function merchantProfilePictureShow(Request $request, Merchant $merchant)
    {
        if ($request->hasValidSignature()) {
            return $this->streamMerchantAsset($merchant->logo_path);
        }

        return $this->streamMerchantAsset($merchant->logo_path);
    }

    public function merchantBannerShow(Request $request, Merchant $merchant)
    {
        if ($request->hasValidSignature()) {
            return $this->streamMerchantAsset($merchant->cover_path);
        }

        return $this->streamMerchantAsset($merchant->cover_path);
    }

    /**
     * Return ALL merchants owned by the authenticated user.
     */
    public function myMerchants(Request $request)
    {
        $user = $request->user();

        $merchants = Merchant::query()
            ->where('user_id', $user->id)
            ->with([
                'segmentation:id,name',
                'primaryAddress',
                'primaryAddress.province:id,name',
                'primaryAddress.city:id,name',
                'primaryAddress.district:id,name',
                'primaryAddress.village:id,name',
            ])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $merchants,
        ]);
    }
    /**
     * ✅ NEW: Public endpoint untuk list merchants
     * Menampilkan merchant yang sudah approved
     */
    public function publicIndex(Request $request)
    {
        $perPage = $request->input('per_page', 12);
        $search = $request->input('search');
        $segmentationId = $request->input('segmentation_id');
        $cityId = $request->input('city_id');
        $random = $request->boolean('random', false); // Default false

        $query = Merchant::with([
            'segmentation:id,name',
            'primaryAddress', // ✅ Load full address relation
            'primaryAddress.province:id,name',
            'primaryAddress.city:id,name', // ✅ Ini akan load dari Regency
            'primaryAddress.district:id,name',
        ])
            ->where('status', 'approved')
            ->withCount('products'); // Hitung jumlah produk

        // Filter by search (nama merchant)
        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        // Filter by segmentation
        if ($segmentationId) {
            $query->where('segmentation_id', $segmentationId);
        }

        // Filter by city
        if ($cityId) {
            $query->whereHas('primaryAddress', function ($q) use ($cityId) {
                $q->where('city_id', $cityId);
            });
        }

        // ✅ Random order jika diminta
        if ($random) {
            $query->inRandomOrder();
        } else {
            $query->latest(); // Default: newest first
        }

        $merchants = $query->paginate($perPage);

        return response()->json($merchants);
    }

    /**
     * Public endpoint untuk random merchants
     * Khusus untuk homepage/recommendation
     * 
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

        // Pastikan latitude dan longitude selalu ada di response (ambil dari primaryAddress jika ada)
        $lat = null;
        $lng = null;
        if ($merchant->primaryAddress) {
            $lat = $merchant->primaryAddress->latitude;
            $lng = $merchant->primaryAddress->longitude;
        }
        // Fallback jika merchant punya field langsung (opsional)
        if (!$lat && isset($merchant->latitude)) {
            $lat = $merchant->latitude;
        }
        if (!$lng && isset($merchant->longitude)) {
            $lng = $merchant->longitude;
        }

        $data = $merchant->toArray();
        $data['latitude'] = $lat;
        $data['longitude'] = $lng;

        return response()->json([
            'data' => $data,
        ]);
    }

    // Customer mendaftar UMKM -> status pending
    public function register(Request $request)
    {
        $user = $request->user();

        // Wajib punya role "customer" - Using hasRole helper for safety
        if (!$user->hasRole('customer')) {
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


    // 🆕 ADDED from feat/rating-system: Admin menyetujui pendaftaran
    /**
     * Admin approves merchant registration
     * - Sets status to 'approved'
     * - Generates slug if not exists
     * - Assigns 'umkm-owner' role to user
     */
    public function approve(Request $request, Merchant $merchant)
    {
        // Validasi role admin
        $admin = $request->user();
        if (!$admin->hasRole('admin')) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if ($merchant->status === 'approved') {
            return response()->json(['message' => 'Merchant sudah disetujui.'], 422);
        }
        if ($merchant->status === 'rejected') {
            return response()->json(['message' => 'Merchant sudah ditolak.'], 422);
        }

        DB::transaction(function () use ($merchant) {
            // ✅ Ensure slug exists (safety check)
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

                // ✅ Use updateOrInsert untuk avoid duplicate entry
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

    // 🆕 ADDED from feat/rating-system: Admin menolak pendaftaran
    /**
     * Admin rejects merchant registration
     * - Sets status to 'rejected'
     */
    public function reject(Request $request, Merchant $merchant)
    {
        $admin = $request->user();
        if (!$admin->hasRole('admin')) {
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

    // 🆕 ADDED from feat/rating-system: UMKM owner lihat profile sendiri
    /**
     * Get merchant profile for authenticated UMKM owner
     * Only returns merchant if it belongs to authenticated user
     */
    public function showMyMerchant(Request $request, $id)
    {
        $user = $request->user();

        $merchant = Merchant::with([
            'segmentation',
            'paguyuban',
            'primaryAddress.province',
            'primaryAddress.city',
            'primaryAddress.district',
            'primaryAddress.village',
        ])
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Fallback: beberapa data lama mungkin tidak memakai label 'utama'
        // sehingga relasi primaryAddress null. Untuk kebutuhan edit form,
        // gunakan alamat terakhir bila primaryAddress tidak ditemukan.
        if (!$merchant->primaryAddress) {
            $fallback = $merchant->addresses()
                ->with([
                    'province:id,name',
                    'city:id,name',
                    'district:id,name',
                    'village:id,name',
                ])
                ->latest('id')
                ->first();

            if ($fallback) {
                $merchant->setRelation('primaryAddress', $fallback);
            }
        }

        return response()->json([
            'data' => $merchant,
        ]);
    }

    // 🆕 ADDED from feat/rating-system: UMKM owner update profile
    /**
     * Update merchant profile by UMKM owner
     * Includes logo & cover image upload
     */
    public function updateMyMerchant(Request $request, $merchant)
    {
        $user = $request->user();
        Log::info('Merchant update request:', [
            'merchant_id' => $merchant,
            'user_id' => $user->id,
            'input' => $request->all(),
            'files' => array_keys($request->allFiles())
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string'],

            // address
            'province_id' => ['nullable', 'integer'],
            'city_id' => ['nullable', 'integer'],
            'district_id' => ['nullable', 'integer'],
            'village_id' => ['nullable', 'integer'],
            'address_detail' => ['nullable', 'string'],

            // coordinate
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],

            // images - more permissive validation
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:2048'],
            'cover' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:4096'],

            // operational hours
            'operational_hours' => ['nullable', 'string'], // JSON string
        ], [
            'logo.mimes' => 'Logo harus berupa file gambar (jpg, jpeg, png, gif, webp)',
            'logo.max' => 'Ukuran logo maksimal 2MB',
            'cover.mimes' => 'Cover harus berupa file gambar (jpg, jpeg, png, gif, webp)',
            'cover.max' => 'Ukuran cover maksimal 4MB',
        ]);

        DB::beginTransaction();

        try {
            /** ===============================
             * Update merchant basic info
             * =============================== */
            $merchant = Merchant::find($merchant);
            $merchant->update([
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
                'description' => $validated['description'] ?? null,
            ]);

            /** ===============================
             * Address (primary address)
             * =============================== */
            $address = $merchant->primaryAddress;

            if (!$address) {
                $address = $merchant->addresses()->create([
                    'label' => 'utama',
                    'province_id' => $validated['province_id'] ?? null,
                    'city_id' => $validated['city_id'] ?? null,
                    'district_id' => $validated['district_id'] ?? null,
                    'village_id' => $validated['village_id'] ?? null,
                    'detail' => $validated['address_detail'] ?? null,
                    'latitude' => $validated['latitude'] ?? null,
                    'longitude' => $validated['longitude'] ?? null,
                ]);
            } else {
                $address->update([
                    'province_id' => $validated['province_id'] ?? null,
                    'city_id' => $validated['city_id'] ?? null,
                    'district_id' => $validated['district_id'] ?? null,
                    'village_id' => $validated['village_id'] ?? null,
                    'detail' => $validated['address_detail'] ?? null,
                    'latitude' => $validated['latitude'] ?? null,
                    'longitude' => $validated['longitude'] ?? null,
                ]);
            }

            /** ===============================
             * Operational Hours
             * =============================== */
            if ($request->filled('operational_hours')) {
                $merchant->operational_hours = json_decode(
                    $request->operational_hours,
                    true
                );
                $merchant->save();
            }

            /** ===============================
             * Logo Upload
             * =============================== */
            if ($request->hasFile('logo')) {
                if ($merchant->logo_path && Storage::disk('public')->exists($merchant->logo_path)) {
                    Storage::disk('public')->delete($merchant->logo_path);
                }

                $path = $request->file('logo')->store('merchants/logos', 'public');

                $merchant->update([
                    'logo_path' => $path
                ]);
            }

            /** ===============================
             * Cover Upload (saved to merchant directly)
             * =============================== */
            if ($request->hasFile('cover')) {
                // Delete old cover if exists
                if ($merchant->cover_path && Storage::disk('public')->exists($merchant->cover_path)) {
                    Storage::disk('public')->delete($merchant->cover_path);
                }

                $path = $request->file('cover')->store('merchants/covers', 'public');
                $merchant->update(['cover_path' => $path]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Profil UMKM berhasil diperbarui',
                'data' => $merchant->fresh()->load(['segmentation', 'primaryAddress']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error updating merchant profile: ' . $e->getMessage());

            return response()->json([
                'message' => 'Gagal memperbarui profil',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    private function streamMerchantAsset(?string $assetPath)
    {
        if (empty($assetPath)) {
            abort(404);
        }

        // For safety, only support local storage paths.
        if (str_starts_with($assetPath, 'http://') || str_starts_with($assetPath, 'https://')) {
            abort(404);
        }

        $disk = 'public';
        $path = ltrim($assetPath, '/');

        if (!Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };

        $stream = Storage::disk($disk)->readStream($path);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

}
