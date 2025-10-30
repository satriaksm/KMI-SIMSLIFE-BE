<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;

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
    }
}