<?php

namespace App\Http\Controllers\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        // ✅ For SPA/API: Use Sanctum token instead of session
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->roles->pluck('name'),
                'merchants' => $user->merchants ? $user->merchants->map(function ($merchant) {
                    return [
                        'id' => $merchant->id,
                        'name' => $merchant->name,
                        'status' => $merchant->status,
                        'segmentation_id' => $merchant->segmentation_id,
                        'segmentation' => $merchant->segmentation ? [
                            'id' => $merchant->segmentation->id,
                            'name' => $merchant->segmentation->name,
                        ] : null,
                    ];
                }) : [],
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load([
            'roles:id,name', // ✅ Only select needed columns
            'merchants' => function ($query) {
                $query->select('id', 'user_id', 'name', 'status', 'segmentation_id')
                    ->where('status', 'approved')
                    ->with('segmentation:id,name');
            }
        ]);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->roles->pluck('name'),
            'merchants' => $user->merchants->map(function ($merchant) {
                return [
                    'id' => $merchant->id,
                    'name' => $merchant->name,
                    'status' => $merchant->status,
                    'segmentation_id' => $merchant->segmentation_id,
                    'segmentation' => $merchant->segmentation ? [
                        'id' => $merchant->segmentation->id,
                        'name' => $merchant->segmentation->name,
                    ] : null,
                ];
            }),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logout berhasil.']);
    }
}