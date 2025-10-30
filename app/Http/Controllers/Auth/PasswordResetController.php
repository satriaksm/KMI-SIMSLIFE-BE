<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Routing\Controller as Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

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

        // Hindari user enumeration: selalu 200 bila bukan throttled
        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => __('passwords.sent')])
            : response()->json(['message' => 'Tautan reset kata sandi telah dikirim.']);
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
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->password = $data['password'];
        $user->save();

        // Revoke current token (optional security)
        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'Password changed. Please log in again.']);
    }
}
