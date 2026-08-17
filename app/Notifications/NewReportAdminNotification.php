<?php

namespace App\Notifications;

use App\Models\ContentReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Kirim ke ADMIN saat ada laporan baru masuk.
 */
class NewReportAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected ContentReport $report;

    public function __construct(ContentReport $report)
    {
        $this->report = $report;
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $reportUrl = config('app.frontend_url', config('app.url')) . '/admin/reports/' . $this->report->id;
        $reporterName = $this->report->reporter?->name ?? 'Pengguna';
        $reportableType = $this->getTypeLabel();
        $reasonTitle = $this->report->reason?->reason_title ?? '-';

        return (new MailMessage)
            ->subject('[Laporan Baru] ' . $reportableType . ' - Sumilir Marketplace')
            ->view('emails.reports.new-admin', [
                'report'       => $this->report,
                'reportUrl'    => $reportUrl,
                'reporterName' => $reporterName,
                'typeLabel'    => $reportableType,
                'reasonTitle'  => $reasonTitle,
                'adminName'    => $notifiable->name,
                'targetName'   => $this->report->getTargetName(),
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'report_id'       => $this->report->id,
            'type'            => 'new_report',
            'title'           => 'Laporan baru masuk',
            'message'         => 'Laporan ' . $this->getTypeLabel() . ' baru dari ' . ($this->report->reporter?->name ?? 'pengguna'),
            'action_url'      => '/admin/reports/' . $this->report->id,
            'reportable_type' => class_basename($this->report->reportable_type),
            'reporter_name'   => $this->report->reporter?->name,
            'created_at'      => now()->toIso8601String(),
        ];
    }

    private function getTypeLabel(): string
    {
        $type = strtolower(class_basename($this->report->reportable_type ?? ''));
        return match ($type) {
            'product'       => 'Produk',
            'jasa'          => 'Jasa',
            'merchant'      => 'Merchant',
            'communitypost' => 'Postingan',
            'postcomment'   => 'Komentar',
            'user'          => 'Pengguna',
            default         => ucfirst($type),
        };
    }
}
