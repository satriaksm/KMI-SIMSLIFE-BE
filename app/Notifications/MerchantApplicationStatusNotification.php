<?php

namespace App\Notifications;

use App\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MerchantApplicationStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Merchant $merchant,
        protected string $status,
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $approved = $this->status === 'approved';
        $subject = $approved
            ? 'Pendaftaran UMKM Anda Disetujui'
            : 'Pendaftaran UMKM Anda Ditolak';

        $dashboardUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/') . '/dashboard';

        $message = (new MailMessage)
            ->subject($subject)
            ->greeting('Halo ' . $notifiable->name)
            ->line($approved
                ? 'Pendaftaran UMKM Anda telah disetujui oleh admin.'
                : 'Pendaftaran UMKM Anda belum disetujui oleh admin.');

        if ($approved) {
            $message->line('Silakan login ke dashboard untuk melanjutkan pengelolaan UMKM Anda.');
        } else {
            $message->line('Jika Anda ingin mencoba lagi, silakan periksa kembali data pendaftaran Anda.');
        }

        return $message
            ->line('Nama UMKM: ' . $this->merchant->name)
            ->action('Buka Dashboard', $dashboardUrl)
            ->line('Terima kasih telah menggunakan SUMILIR.');
    }

    public function toArray($notifiable): array
    {
        return [
            'merchant_id' => $this->merchant->id,
            'merchant_name' => $this->merchant->name,
            'status' => $this->status,
            'message' => $this->status === 'approved'
                ? 'Pendaftaran UMKM Anda telah disetujui.'
                : 'Pendaftaran UMKM Anda ditolak.',
        ];
    }
}
