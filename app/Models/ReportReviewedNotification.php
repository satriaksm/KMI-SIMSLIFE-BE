<?php

namespace App\Notifications;

use App\Models\ContentReport;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ReportReviewedNotification extends Notification
{
    use Queueable;

    protected $report;

    public function __construct(ContentReport $report)
    {
        $this->report = $report;
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $statusText = [
            'resolved' => 'telah ditindaklanjuti',
            'dismissed' => 'ditolak',
            'in_review' => 'sedang ditinjau',
        ];

        return (new MailMessage)
            ->subject('Laporan Konten Anda ' . ($statusText[$this->report->status] ?? 'diperbarui'))
            ->greeting('Halo ' . $notifiable->name)
            ->line('Laporan konten Anda telah ' . ($statusText[$this->report->status] ?? 'diperbarui') . ' oleh admin.')
            ->line('Status: ' . ucfirst($this->report->status))
            ->when($this->report->admin_note, function($mail) {
                return $mail->line('Catatan Admin: ' . $this->report->admin_note);
            })
            ->line('Terima kasih telah membantu menjaga kualitas konten di SUMILIR.');
    }

    public function toArray($notifiable)
    {
        return [
            'report_id' => $this->report->id,
            'status' => $this->report->status,
            'admin_note' => $this->report->admin_note,
            'reviewed_at' => $this->report->reviewed_at,
        ];
    }
}