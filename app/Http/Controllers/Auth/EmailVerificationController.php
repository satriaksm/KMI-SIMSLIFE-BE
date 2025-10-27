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
        $user = User::findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Invalid verification link.'], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['message' => 'Email verified.']);
    }

    public function send(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        // Optional: ensure request is from a signed route to avoid abuse
        if (!URL::hasValidSignature($request)) {
            // If you keep 'signed' middleware on the route, this check is redundant
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.']);
    }
}
