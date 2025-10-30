<?php

namespace App\Http\Controllers\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Routing\Controller as Controller;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{


    public function register(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'phone' => ['nullable', 'string', 'max:13'],
                'nik' => ['required', 'string', 'size:16', 'unique:users,nik'],
                'password' => [
                    'required',
                    'confirmed',
                    Password::min(8),
                    'regex:/^(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*\-_]).+$/',
                ],
            ],
            [
                'name.required' => 'Nama wajib diisi.',
                'email.required' => 'Email wajib diisi.',
                'email.email' => 'Format email tidak valid.',
                'email.unique' => 'Email sudah terdaftar.',
                'password.required' => 'Password wajib diisi.',
                'password.confirmed' => 'Konfirmasi password tidak cocok.',
                'password.min' => 'Password minimal 8 karakter.',
                'password.regex' => 'Password harus mengandung huruf besar, angka, dan simbol (!@#$%^&*-_).',
                'nik.size' => 'NIK harus 16 karakter.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Data yang diberikan tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'nik' => $data['nik'],
            'password' => Hash::make($data['password']),
            'status' => 'active',
        ]);

        // Tetapkan role default 'customer'
        $role = Role::firstOrCreate(['name' => 'customer']);
        $user->roles()->syncWithoutDetaching([$role->id]);

        event(new Registered($user)); // kirim email verifikasi

        return response()->json([
            'message' => 'Registrasi berhasil. Silakan verifikasi email Anda sebelum login.',
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
            return response()->json(['message' => 'Kredensial tidak valid.'], 422);
        }

        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email belum terverifikasi.',
                'need_verify' => true,
            ], 403);
        }

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
