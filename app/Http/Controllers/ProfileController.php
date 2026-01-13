<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProfileController
{
    public function show(Request $request)
    {
        $user = $request->user();
        if (!$user instanceof User) {
            abort(401);
        }

        return $user->load([
            'primaryAddress.province',
            'primaryAddress.city',
            'primaryAddress.district',
            'primaryAddress.village',
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        if (!$user instanceof User) {
            abort(401);
        }

        Log::info('Profile update raw input:', $request->all());
        Log::info('Profile update files:', $request->allFiles());

        $rules = [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'nik' => 'sometimes|nullable|string|max:20',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'profile_picture' => 'sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:5048',
            // 'full_address' => 'sometimes|string',
        ];

        if ($request->hasFile('profile_picture')) {
            $rules['profile_picture'] = 'image|mimes:jpeg,png,jpg,gif,svg|max:5048';
        } else {
            $rules['profile_picture'] = 'sometimes|string';
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rules);


        if ($validator->fails()) {
            Log::warning('Profile update validation failed:', $validator->errors()->toArray());
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validatedData = $validator->validated();

        try {
            if ($request->hasFile('profile_picture')) {
                $file = $request->file('profile_picture');
                Log::info('Processing profile picture file:', [
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ]);

                // Delete old picture if it exists
                if ($user->profile_picture_path) {
                    Storage::disk('public')->delete($user->profile_picture_path);
                }
                $path = $file->store('profile_pictures', 'public');
                $user->profile_picture_path = $path;
            }

            // Only update explicit user fields — do NOT create or update addresses here.
            // (The system keeps `full_address` as a plain text column on users.)
            $user->name = $validatedData['name'] ?? $user->name;
            $user->phone = $validatedData['phone'] ?? $user->phone;

            // Ensure NIK is null if empty to avoid unique constraint issues
            $newNik = isset($validatedData['nik']) ? trim($validatedData['nik']) : $user->nik;
            $user->nik = ($newNik === '' || $newNik === 'null' || $newNik === null) ? null : $newNik;

            $user->email = $validatedData['email'] ?? $user->email;
            // $user->full_address = $validatedData['full_address'] ?? $user->full_address;

            Log::info('Prepared user for save:', [
                'id' => $user->id,
                'nik' => $user->nik,
                'email' => $user->email
            ]);

            $user->save();

            $freshUser = $user->fresh()->load([
                'primaryAddress.province',
                'primaryAddress.city',
                'primaryAddress.district',
                'primaryAddress.village',
            ]);

            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => $freshUser,
            ]);

        } catch (\Exception $e) {
            Log::error('Profile update failed with exception:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Internal server error during profile update.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();
        if (!$user instanceof User) {
            abort(401);
        }

        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password does not match'], 422);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json(['message' => 'Password changed successfully']);
    }

    public function addressShow(Request $request)
    {
        $user = $request->user();

        $address = $user->primaryAddress()
            ->with(['province', 'city', 'district', 'village'])
            ->first();

        return response()->json([
            'data' => $address,
        ]);
    }

    public function addressUpsert(Request $request)
    {

        $user = $request->user();

        $validated = $request->validate([
            'province_id' => ['required', 'integer', 'exists:provinces,id'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'district_id' => ['required', 'integer', 'exists:districts,id'],
            'village_id' => ['required', 'integer', 'exists:villages,id'],
            'detail' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $address = $user->addresses()->updateOrCreate(
            ['label' => 'utama'],
            [
                'province_id' => $validated['province_id'],
                'city_id' => $validated['city_id'],
                'district_id' => $validated['district_id'],
                'village_id' => $validated['village_id'],
                'detail' => $validated['detail'] ?? null,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
            ]
        );

        $address->load(['province', 'city', 'district', 'village']);

        return response()->json([
            'message' => 'Address updated successfully',
            'data' => $address,
        ]);
    }

    public function profilePictureShow(Request $request, User $user)
    {
        // Signed URL is supported (mirrors Product Image access pattern)
        if ($request->hasValidSignature()) {
            return $this->streamUserProfilePicture($user);
        }

        // For now, profile pictures are treated as public avatars.
        // If you want to restrict this later, add auth/ownership checks here.
        return $this->streamUserProfilePicture($user);
    }

    private function streamUserProfilePicture(User $user)
    {
        if (empty($user->profile_picture_path)) {
            abort(404);
        }

        $disk = 'public';
        $path = ltrim($user->profile_picture_path, '/');

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
