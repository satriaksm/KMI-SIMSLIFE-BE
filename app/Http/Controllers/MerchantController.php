<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class MerchantController
{
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

        // Optional: cegah multi-pendaftaran saat masih pending/approved
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
                'description' => ['nullable', 'string'],
                'segmentation_id' => ['required', 'integer', Rule::exists('segmentations', 'id')],

                'address.province_id' => ['required', 'integer', Rule::exists('provinces', 'id')],
                'address.city_id' => ['required', 'integer', Rule::exists('cities', 'id')],
                'address.district_id' => ['required', 'integer', Rule::exists('districts', 'id')],
                'address.village_id' => ['required', 'integer', Rule::exists('villages', 'id')],
                'address.detail' => ['nullable', 'string', 'max:500'],
                'address.latitude' => ['required', 'numeric', 'between:-90,90'],
                'address.longitude' => ['required', 'numeric', 'between:-180,180'],
            ],
            [
                'name.required' => 'Nama usaha wajib diisi.',
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
        // Validasi role admin (tanpa Spatie): cek di pivot role_user
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
            $merchant->update([
                'status' => 'approved',
                'response_at' => Carbon::now(),
            ]);

            // Beri role "umkm-owner" (tanpa Spatie, langsung via roles & role_user)
            $owner = $merchant->user;
            if ($owner) {
                // Ambil/buat role
                $roleId = DB::table('roles')->where('name', 'umkm-owner')->value('id');
                if (!$roleId) {
                    $roleId = DB::table('roles')->insertGetId([
                        'name' => 'umkm-owner',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Pasang di pivot role_user (hindari duplikasi)
                DB::table('role_user')->updateOrInsert(
                    ['user_id' => $owner->id, 'role_id' => $roleId],
                    ['updated_at' => now(), 'created_at' => now()]
                );
            }
        });

        return response()->json([
            'message' => 'Merchant disetujui.',
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

        // Optional: validasi alasan penolakan jika Anda punya kolom review_notes
        // $request->validate(['reason' => ['nullable','string','max:500']]);

        $merchant->update([
            'status' => 'rejected',
            'response_at' => Carbon::now(),
            // 'review_notes' => $request->input('reason') ?? null, // jika kolom tersedia
        ]);

        return response()->json([
            'message' => 'Merchant ditolak.',
            'merchant' => $merchant->fresh()->load(['segmentation', 'primaryAddress']),
        ]);
    }
}
