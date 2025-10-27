<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Routing\Controller as Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'role' => ['nullable', 'string'], // optional: name/slug of role to attach
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'status' => 'active',
        ]);

        // Attach default role by name only
        $roleKey = $data['role'] ?? 'user';
        $role = Role::where('name', $roleKey)->first();
        if ($role) {
            $user->roles()->attach($role->id);
        }

        // Send email verification
        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Registered. Please verify your email.',
            'user' => $user->load('roles'),
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::with('roles')->where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        // Create token abilities from role names only
        $abilities = $user->roles->map(fn($r) => 'role:' . strtolower($r->name))->all();
        if (empty($abilities)) {
            $abilities = ['*'];
        }

        $token = $user->createToken($credentials['device_name'] ?? 'api', $abilities)->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('roles'));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
