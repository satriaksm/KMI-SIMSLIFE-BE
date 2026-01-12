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
        return $request->user();
    }

    public function update(Request $request)
    {
        $user = Auth::user();

        Log::info('Profile update raw input:', $request->all());
        Log::info('Profile update files:', $request->allFiles());

        $rules = [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'nik' => 'sometimes|nullable|string|max:20',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'full_address' => 'sometimes|string',
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
            $user->full_address = $validatedData['full_address'] ?? $user->full_address;

            Log::info('Prepared user for save:', [
                'id' => $user->id,
                'nik' => $user->nik,
                'email' => $user->email
            ]);

            $user->save();

            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => $user->fresh(),
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
        $user = Auth::user();

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
}
