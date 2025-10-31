<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        // ...existing code...
    ];

    public function boot(): void
    {
        // ... code...

        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontend = config('app.frontend_url', 'http://localhost:5173');
            $email = urlencode($notifiable->getEmailForPasswordReset());
            return "{$frontend}/reset-password?token={$token}&email={$email}";
        });

        // Buat link verifikasi ke route API (tanpa auth)
        VerifyEmail::createUrlUsing(function ($notifiable) {
            $expire = Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60));
            return URL::temporarySignedRoute(
                'api.verification.verify',
                $expire,
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
        });
    }
}
