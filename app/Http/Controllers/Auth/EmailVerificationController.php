<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Routing\Controller as Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, $id, $hash)
    {
        $frontend = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        $redirect = function (string $url) {
            // Jangan pakai helper redirect() di route API karena bisa butuh session middleware.
            return response('', 302)->header('Location', $url);
        };

        // Validasi signature dari email.
        // TrustProxies (*) is configured in bootstrap/app.php so $request->url() returns
        // the correct HTTPS scheme even on Hostinger's SSL-terminated shared hosting.
        if (!URL::hasValidSignature($request)) {
            return $redirect($frontend . '/verify-email?status=invalid');
        }

        $user = User::findOrFail($id);

        // Cek hash email
        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return $redirect($frontend . '/verify-email?status=invalid');
        }

        // Jika sudah pernah terverifikasi
        if ($user->hasVerifiedEmail()) {
            return $redirect($frontend . '/verify-email?status=already_verified&email=' . urlencode($user->email));
        }

        // Tandai sebagai terverifikasi
        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $redirect($frontend . '/verify-email?status=verified&email=' . urlencode($user->email));
    }

    public function send(Request $request)
    {
        $request->user()->sendEmailVerificationNotification();
        return response()->json(['message' => 'Verification link sent.'], 202);
    }

    public function resendPublic(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $request->input('email'))->first();

        // Samakan respons agar tidak bocorkan apakah email terdaftar
        if (!$user) {
            return response()->json(['message' => 'If the email exists, a verification link has been sent.'], 200);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 200);
        }

        $user->sendEmailVerificationNotification();
        return response()->json(['message' => 'Verification link sent.'], 202);
    }
}
