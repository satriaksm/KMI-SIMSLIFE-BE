<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Storage;

class ProfileController
{
    public function show(Request $request)
    {
        return $request->user();
    }

    public function update(Request $request)
    {
        $user = Auth::user();

        // Prevent accidental address creation (this app stores full_address as a text field for users)
        // If the client sends an `address` object, require its fields explicitly elsewhere (merchant flow).
        $validatedData = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'nik' => 'sometimes|string|max:20',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'profile_picture' => 'sometimes|image|mimes:jpeg,png,jpg,gif,svg|max:5048',
            // 'full_address' => 'sometimes|string',
        ]);

        if ($request->hasFile('profile_picture')) {
            // Delete old picture if it exists
            if ($user->profile_picture_path) {
                Storage::disk('public')->delete($user->profile_picture_path);
            }
            $path = $request->file('profile_picture')->store('profile_pictures', 'public');
            $user->profile_picture_path = $path;
        }

        // Only update explicit user fields — do NOT create or update addresses here.
        // (The system keeps `full_address` as a plain text column on users.)
        $user->name = $validatedData['name'] ?? $user->name;
        $user->phone = $validatedData['phone'] ?? $user->phone;
        $user->nik = $validatedData['nik'] ?? $user->nik;
        $user->email = $validatedData['email'] ?? $user->email;
        // $user->full_address = $validatedData['full_address'] ?? $user->full_address;

        // Do NOT create/update related `addresses` from this endpoint. The user's full address
        // is stored on `users.full_address` (plain text). If you need address relations, use
        // the appropriate endpoints that handle address details and required IDs.

        try {
            $user->save();
        } catch (\Illuminate\Database\QueryException $e) {
            // Return a friendly validation-like error instead of 500 when DB constraint fails
            return response()->json([
                'message' => 'Failed to update profile due to invalid or incomplete related data.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->fresh(),
        ]);
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
