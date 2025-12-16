<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Routing\Controller as Controller;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->only('email'))->first();

        if (!$user) {
            return response()->json([
                'message' => 'Email tidak terdaftar.',
            ], 404);
        }
        $requireVerification = config('auth.verify_email_before_reset', false);

        if ($requireVerification && !$user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email belum diverifikasi. Silakan verifikasi email Anda terlebih dahulu.',
            ], 403);
        }

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_THROTTLED) {
            $broker = config('auth.defaults.passwords', 'users');
            $seconds = (int) config("auth.passwords.{$broker}.throttle", 60);

            return response()
                ->json([
                    'message' => __('passwords.throttled'),
                    'retry_after' => $seconds,
                ], 429)
                ->header('Retry-After', $seconds);
        }

        if ($status === Password::INVALID_USER) {
            return response()->json([
                'message' => 'Email tidak terdaftar.',
            ], 404);
        }

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => __('passwords.sent'),
            ]);
        }

        return response()->json([
            'message' => 'Tautan reset kata sandi telah dikirim.',
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)]);
                $user->setRememberToken(Str::random(60));
                $user->save();

                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 422);
    }

    public function change(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $user = $request->user();
        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Kata sandi saat ini tidak cocok.'], 422);
        }

        $user->password = $data['password'];
        $user->save();

        // Revoke current token (optional security)
        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'Kata sandi berhasil diubah.']);
    }
}
