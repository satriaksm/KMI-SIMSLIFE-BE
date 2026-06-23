<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\PushSubscription;
use App\Models\User;
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

    public function status(Request $request)
    {
        $audiences = $request->user()
            ->pushSubscriptions()
            ->pluck('audiences')
            ->filter(fn ($items) => is_array($items))
            ->flatten()
            ->unique()
            ->values()
            ->all();

        return ApiResponse::success([
            'audiences' => $audiences,
            'has_subscription' => !empty($audiences),
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

        /** @var User $user */
        $user = $request->user();
        $userId = (int) $user->id;
        $audiences = $this->resolveAudiencesForUser($user);

        $subscription = PushSubscription::query()->updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'user_id' => $userId,
                'p256dh' => $validated['keys']['p256dh'],
                'auth' => $validated['keys']['auth'],
                'content_encoding' => $validated['contentEncoding'] ?? null,
                'expiration_time' => $validated['expirationTime'] ?? null,
                'audiences' => $audiences,
            ]
        );

        return ApiResponse::success($subscription, 'Push subscription tersimpan.');
    }

    public function destroy(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'endpoint' => ['required', 'string', 'max:512'],
        ])->validate();

        $deleted = PushSubscription::query()
            ->where('endpoint', $validated['endpoint'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return ApiResponse::success([
            'deleted' => $deleted,
        ], 'Push subscription dihapus.');
    }

    /**
     * Satu langganan per perangkat: pembeli selalu dapat notif customer;
     * notif pesanan masuk UMKM hanya jika akun ini memiliki toko.
     *
     * @return list<string>
     */
    private function resolveAudiencesForUser(User $user): array
    {
        $audiences = ['customer'];

        if ($user->merchants()->exists()) {
            $audiences[] = 'merchant';
        }

        return $audiences;
    }
}
