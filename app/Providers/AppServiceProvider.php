<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Verifikasi Email (pakai URL signed default dari Laravel)
        VerifyEmail::toMailUsing(function ($notifiable, string $url) {
            return (new MailMessage)
                ->subject('Verifikasi Email Akun SUMILIR')
                ->greeting('Halo, ' . ($notifiable->name ?? 'Pengguna'))
                ->line('Terima kasih telah mendaftar di SUMILIR.')
                ->line('Silakan klik tombol di bawah ini untuk memverifikasi email Anda.')
                ->action('Verifikasi Email', $url)
                ->line('Jika Anda tidak merasa membuat akun, abaikan email ini.')
                ->salutation('Salam hangat, Tim SUMILIR')
                ->markdown('emails.auth.verify-email', [
                    'actionUrl' => $url,
                    'userName' => $notifiable->name ?? 'Pengguna',
                ]);
        });

        // Reset Password (arah ke FE)
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $frontend = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173'));
            $resetUrl = rtrim($frontend, '/') . '/reset-password/' . $token . '?email=' . urlencode($notifiable->email);

            return (new MailMessage)
                ->subject('Reset Password Akun SUMILIR')
                ->greeting('Halo, ' . ($notifiable->name ?? 'Pengguna'))
                ->line('Kami menerima permintaan untuk mengatur ulang password akun Anda.')
                ->line('Klik tombol di bawah untuk melanjutkan.')
                ->action('Atur Ulang Password', $resetUrl)
                ->line('Abaikan email ini jika Anda tidak meminta reset password.')
                ->salutation('Salam, Tim SUMILIR')
                ->markdown('emails.auth.reset-password', [
                    'actionUrl' => $resetUrl,
                    'userName' => $notifiable->name ?? 'Pengguna',
                ]);
        });
    }
}
