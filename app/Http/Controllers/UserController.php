<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Get authenticated user profile
     */
    public function show(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $request->user(),
        ]);
    }

    /**
     * Update authenticated user profile
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => $user,
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update([
            'password' => bcrypt($validated['password']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully',
        ]);
    }

    /**
     * Get user address
     */
    public function addressShow(Request $request)
    {
        $user = $request->user();
        $address = $user->primaryAddress;

        return response()->json([
            'success' => true,
            'data' => $address ? [
                'id' => $address->id,
                'province_id' => $address->province_id,
                'city_id' => $address->city_id,
                'district_id' => $address->district_id,
                'village_id' => $address->village_id,
                'detail' => $address->detail,
                'label' => $address->label,
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
            ] : null,
        ]);
    }

    /**
     * Create or update user address
     */
    public function addressUpsert(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'province_id' => ['required', 'integer'],
            'city_id' => ['required', 'integer'],
            'district_id' => ['required', 'integer'],
            'village_id' => ['required', 'integer'],
            'detail' => ['required', 'string'],
            'label' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        // Upsert primary address
        $address = $user->addresses()->updateOrCreate(
            ['label' => $validated['label'] ?? 'utama'],
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'Address updated successfully',
            'data' => [
                'id' => $address->id,
                'province_id' => $address->province_id,
                'city_id' => $address->city_id,
                'district_id' => $address->district_id,
                'village_id' => $address->village_id,
                'detail' => $address->detail,
                'label' => $address->label,
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
            ],
        ]);
    }

    /**
     * Delete user account
     */
    public function destroy(Request $request)
    {
        $user = $request->user();
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Account deleted successfully',
        ]);
    }

    /**
     * Show user profile picture
     */
    public function profilePictureShow(User $user)
    {
        if (!$user->profile_picture || !Storage::disk('public')->exists($user->profile_picture)) {
            return response()->file(public_path('images/default-avatar.png'));
        }

        return response()->file(Storage::disk('public')->path($user->profile_picture));
    }
}
