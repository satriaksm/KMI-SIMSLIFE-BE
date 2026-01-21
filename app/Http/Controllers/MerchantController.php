<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Address;
use App\Models\Merchant;
use Illuminate\Support\Arr;
use App\Helpers\ApiResponse;
use App\Services\Deletion\HardDeleteService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MerchantController extends Controller
{

    /**
     * UMKM owner: delete own merchant (hard delete)
     * DELETE /api/merchant/{merchant:slug}
     */
    public function destroyMyMerchant(Request $request, Merchant $merchant, HardDeleteService $deleter)
    {
        $this->authorize('delete', $merchant);

        try {
            DB::transaction(function () use ($deleter, $merchant) {
                $deleter->deleteMerchant($merchant);
            });

            return ApiResponse::success(null, 'UMKM berhasil dihapus.');
        } catch (\Throwable $e) {
            Log::error('[MerchantController] Failed to delete merchant', [
                'merchant_id' => $merchant->id,
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error(
                'Gagal menghapus UMKM.',
                500,
                config('app.debug') ? [$e->getMessage()] : null
            );
        }
    }

    public function merchantProfilePictureShow(Request $request, Merchant $merchant, ?string $v = null)
    {
        if ($request->hasValidSignature()) {
            return $this->streamMerchantAsset($merchant->logo_path);
        }

        return $this->streamMerchantAsset($merchant->logo_path);
    }

    public function merchantBannerShow(Request $request, Merchant $merchant, ?string $v = null)
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
            ->where('status', '!=', 'rejected')
            ->select(['id', 'name', 'slug', 'segmentation_id', 'logo_path'])
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success($merchants, 'Berhasil mengambil data merchant milik pengguna.');
    }

    public function mapIndex()
    {
        $merchants = Merchant::query()
            ->where('status', 'approved')
            ->select(['id', 'name', 'slug', 'segmentation_id', 'logo_path'])
            ->with([
                'segmentation:id,name',
                'primaryAddress:id,addressable_id,addressable_type,latitude,longitude,label',
                'addresses:id,addressable_id,addressable_type,latitude,longitude,label',
            ])
            ->get();

        $data = $merchants->map(function (Merchant $merchant) {
            $addr = $merchant->primaryAddress ?: $merchant->addresses->first();

            return [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'slug' => $merchant->slug,
                'logo_url' => $merchant->logo_url,
                'latitude' => $addr?->latitude,
                'longitude' => $addr?->longitude,
                'segmentation' => $merchant->segmentation
                    ? ['id' => $merchant->segmentation->id, 'name' => $merchant->segmentation->name]
                    : null,
            ];
        });

        return ApiResponse::success($data, 'Berhasil mengambil data merchant untuk peta.');

    }

    public function publicShow(Request $request, $merchantSlug)
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
            ->where(function ($q) use ($merchantSlug) {
                $q->where('id', $merchantSlug)
                    ->orWhere('slug', $merchantSlug);
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

        if (isset($data['primary_address'])) {
            $data['primary_address'] = Arr::except($data['primary_address'], [
                'label',
                'created_at',
                'updated_at',
            ]);
        }

        $data['latitude'] = $lat;
        $data['longitude'] = $lng;

        // Hapus field yang tidak ingin ditampilkan
        $data = Arr::except($data, [
            'status',
            'rejection_reason',
            'reviewed_by',
            'response_at',
            'created_at',
            'updated_at',
        ]);

        return ApiResponse::success($data, 'success');
    }

    // Customer mendaftar UMKM -> status pending
    public function register(Request $request)
    {
        $user = $request->user();

        // Wajib punya role "customer" - Using hasRole helper for safety
        if (!$user->hasRole('customer')) {
            return ApiResponse::error(
                'Akses ditolak. Hanya pengguna dengan role customer yang dapat mendaftar UMKM.',
                403
            );
        }

        $already = Merchant::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending'])
            ->exists();
        if ($already) {
            return ApiResponse::error(
                'Anda sudah memiliki pendaftaran UMKM yang menunggu.',
                422
            );
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
            return ApiResponse::error(
                'Data yang diberikan tidak valid.',
                422,
                $validator->errors()
            );
        }

        $validated = $validator->validated();

        $merchant = DB::transaction(function () use ($validated, $user) {
            $operationalHours = [
                'monday' => ['is_open' => false],
                'tuesday' => ['is_open' => false],
                'wednesday' => ['is_open' => false],
                'thursday' => ['is_open' => false],
                'friday' => ['is_open' => false],
                'saturday' => ['is_open' => false],
                'sunday' => ['is_open' => false],
            ];

            $merchant = Merchant::create([
                'user_id' => $user->id,
                'paguyuban_id' => null,
                'segmentation_id' => $validated['segmentation_id'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'phone' => $validated['phone'],
                'logo_path' => null,
                'operational_hours' => $operationalHours,
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

        return ApiResponse::success([
            'merchant' => $merchant->load(['segmentation', 'primaryAddress']),
        ], 'Pendaftaran UMKM berhasil dikirim. Menunggu persetujuan admin.');
    }

    // 🆕 ADDED from feat/rating-system: UMKM owner lihat profile sendiri
    /**
     * Get merchant profile for authenticated UMKM owner
     * Only returns merchant if it belongs to authenticated user
     */
    public function showMyMerchant(Request $request, Merchant $merchant)
    {
        $user = $request->user();

        $this->authorize('view', $merchant);

        $merchant->load([
            'segmentation',
            'paguyuban',
            'primaryAddress.province',
            'primaryAddress.city',
            'primaryAddress.district',
            'primaryAddress.village',
        ]);

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

        return ApiResponse::success($merchant, 'success');

    }

    // 🆕 ADDED from feat/rating-system: UMKM owner update profile
    /**
     * Update merchant profile by UMKM owner
     * Includes logo & cover image upload
     */
    public function updateMyMerchant(Request $request, Merchant $merchant)
    {
        $this->authorize('update', $merchant);

        $user = $request->user();
        Log::info('Merchant update request:', [
            'merchant_id' => $merchant->id,
            'merchant_slug' => $merchant->slug,
            'user_id' => $user->id,
            'input' => $request->all(),
            'files' => array_keys($request->allFiles())
        ]);

        if ((int) $merchant->user_id !== (int) $user->id) {
            abort(404);
        }

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
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
            'cover' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],

            // operational hours
            'operational_hours' => ['nullable', 'string'], // JSON string
        ], [
            'logo.mimes' => 'Logo harus berupa file gambar (jpg, jpeg, png, gif, webp)',
            'logo.max' => 'Ukuran logo maksimal 5MB',
            'cover.mimes' => 'Cover harus berupa file gambar (jpg, jpeg, png, gif, webp)',
            'cover.max' => 'Ukuran cover maksimal 5MB',
        ]);

        DB::beginTransaction();

        try {
            /** ===============================
             * Update merchant basic info
             * =============================== */
            $merchant->update([
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
                'description' => $validated['description'] ?? null,
            ]);

            /** ===============================
             * Address (primary address)
             * =============================== */
            // Prefer primary address (label 'utama').
            // If legacy data doesn't have label 'utama', fallback to the latest address instead of creating a new row.
            $address = $merchant->primaryAddress;
            if (!$address) {
                $address = $merchant->addresses()->latest('id')->first();
            }

            $addressPayload = [];
            if (array_key_exists('province_id', $validated)) {
                $addressPayload['province_id'] = $validated['province_id'];
            }
            if (array_key_exists('city_id', $validated)) {
                $addressPayload['city_id'] = $validated['city_id'];
            }
            if (array_key_exists('district_id', $validated)) {
                $addressPayload['district_id'] = $validated['district_id'];
            }
            if (array_key_exists('village_id', $validated)) {
                $addressPayload['village_id'] = $validated['village_id'];
            }
            if (array_key_exists('address_detail', $validated)) {
                $addressPayload['detail'] = $validated['address_detail'];
            }
            if (array_key_exists('latitude', $validated)) {
                $addressPayload['latitude'] = $validated['latitude'];
            }
            if (array_key_exists('longitude', $validated)) {
                $addressPayload['longitude'] = $validated['longitude'];
            }

            if (!$address) {
                $merchant->addresses()->create(array_merge([
                    'label' => 'utama',
                ], $addressPayload));
            } else {
                // Ensure it becomes primary going forward
                if (empty($address->label)) {
                    $addressPayload['label'] = 'utama';
                }
                $address->update($addressPayload);
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

            return ApiResponse::success([
                'merchant' => $merchant->fresh()->load(['segmentation', 'primaryAddress']),
            ], 'Profil UMKM berhasil diperbarui.');

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error updating merchant profile: ' . $e->getMessage());

            return ApiResponse::error(
                'Gagal memperbarui profil',
                500,
                config('app.debug') ? $e->getMessage() : null
            );
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
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            // URLs are versioned (see {v?} in routes), so we can cache aggressively.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

}
