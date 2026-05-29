<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PushSubscriptionController extends Controller
{
    public function publicKey()
    {
        return response()->json([
            'publicKey' => config('services.webpush.public_key'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'endpoint' => ['required', 'string', 'max:512'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:20'],
            'expirationTime' => ['nullable', 'integer'],
        ])->validate();

        $subscription = $request->user()->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
                'content_encoding' => $validated['contentEncoding'] ?? null,
                'expiration_time' => $validated['expirationTime'] ?? null,
            ]
        );

        return ApiResponse::success($subscription, 'Push subscription tersimpan.');
    }

    public function destroy(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'endpoint' => ['required', 'string', 'max:512'],
        ])->validate();

        $deleted = $request->user()->pushSubscriptions()
            ->where('endpoint', $validated['endpoint'])
            ->delete();

        return ApiResponse::success([
            'deleted' => $deleted,
        ], 'Push subscription dihapus.');
    }
}
