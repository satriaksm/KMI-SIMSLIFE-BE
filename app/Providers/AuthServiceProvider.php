<?php

namespace App\Providers;

use App\Models\Product;
use App\Models\Merchant;
use App\Models\Voucher;
use Illuminate\Support\Carbon;
use App\Policies\ProductPolicy;
use App\Policies\MerchantPolicy;
use App\Policies\VoucherPolicy;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Config;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Product::class => ProductPolicy::class,
        Merchant::class => MerchantPolicy::class,
        Voucher::class => VoucherPolicy::class,
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
