<?php

namespace App\Notifications;

use App\Models\ContentReport;
use App\Models\ReportAppeal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Kirim ke ADMIN saat ada sanggahan baru dari terlapor.
 */
class NewAppealAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected ReportAppeal $appeal;
    protected ContentReport $report;

    public function __construct(ReportAppeal $appeal, ContentReport $report)
    {
        $this->appeal = $appeal;
        $this->report = $report;
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $reviewUrl     = config('app.frontend_url', config('app.url')) . '/admin/reports/' . $this->report->id;
        $appellantName = $this->appeal->appellant?->name ?? 'Pengguna';
        $typeLabel     = $this->getTypeLabel();

        return (new MailMessage)
            ->subject('[Sanggahan Baru] Laporan #' . $this->report->id . ' - Sumilir')
            ->view('emails.reports.appeal-new-admin', [
                'appeal'        => $this->appeal,
                'report'        => $this->report,
                'reviewUrl'     => $reviewUrl,
                'appellantName' => $appellantName,
                'typeLabel'     => $typeLabel,
                'adminName'     => $notifiable->name,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'appeal_id'  => $this->appeal->id,
            'report_id'  => $this->report->id,
            'type'       => 'new_appeal',
            'title'      => 'Sanggahan baru dari terlapor',
            'message'    => ($this->appeal->appellant?->name ?? 'Pengguna') . ' mengajukan sanggahan atas Laporan #' . $this->report->id,
            'action_url' => '/admin/reports/' . $this->report->id,
            'created_at' => now()->toIso8601String(),
        ];
    }

    private function getTypeLabel(): string
    {
        $type = strtolower(class_basename($this->report->reportable_type ?? ''));
        return match ($type) {
            'product'       => 'Produk',
            'jasa'          => 'Jasa',
            'merchant'      => 'UMKM',
            'communitypost' => 'Postingan',
            'postcomment'   => 'Komentar',
            'user'          => 'Akun',
            default         => ucfirst($type),
        };
    }
}
