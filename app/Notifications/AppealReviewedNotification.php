<?php

namespace App\Notifications;

use App\Models\ContentReport;
use App\Models\ReportAppeal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Kirim ke USER (terlapor) saat sanggahannya sudah ditinjau oleh admin.
 */
class AppealReviewedNotification extends Notification implements ShouldQueue
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
        $subject = $this->appeal->status === 'accepted'
            ? '[Sanggahan Diterima] Laporan #' . $this->report->id . ' - Sumilir'
            : '[Sanggahan Ditolak] Laporan #' . $this->report->id . ' - Sumilir';

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.reports.appeal-reviewed', [
                'appeal'   => $this->appeal,
                'report'   => $this->report,
                'userName' => $notifiable->name,
            ]);
    }

    public function toArray($notifiable): array
    {
        $accepted = $this->appeal->status === 'accepted';

        return [
            'appeal_id'      => $this->appeal->id,
            'report_id'      => $this->report->id,
            'type'           => 'appeal_reviewed',
            'title'          => $accepted ? 'Sanggahan Anda diterima' : 'Sanggahan Anda ditolak',
            'message'        => $accepted
                ? 'Admin menerima sanggahan Anda untuk Laporan #' . $this->report->id
                : 'Admin menolak sanggahan Anda untuk Laporan #' . $this->report->id,
            'appeal_status'  => $this->appeal->status,
            'admin_response' => $this->appeal->admin_response,
            'action_url'     => '/reports/' . $this->report->id,
            'created_at'     => now()->toIso8601String(),
        ];
    }
}
