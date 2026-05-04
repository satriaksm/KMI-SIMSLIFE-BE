<?php

namespace App\Notifications;

use App\Models\ContentReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected ContentReport $report;
    protected string $type; // 'status_changed', 'forwarded', 'resolved', 'action_taken'

    public function __construct(ContentReport $report, string $type = 'status_changed')
    {
        $this->report = $report;
        $this->type = $type;
    }

    /**
     * Channels: database, mail (optional)
     */
    public function via($notifiable): array
    {
        return ['database']; // Tambahkan 'mail' jika ingin email notification
    }

    /**
     * Database notification data
     */
    public function toArray($notifiable): array
    {
        $data = [
            'report_id' => $this->report->id,
            'type' => $this->type,
            'status' => $this->report->status,
            'reportable_type' => class_basename($this->report->reportable_type),
            'reportable_id' => $this->report->reportable_id,
            'created_at' => now()->toIso8601String(),
        ];

        // Add type-specific data
        return match($this->type) {
            'status_changed' => array_merge($data, [
                'title' => 'Laporan Anda telah ditinjau',
                'message' => "Status laporan: " . $this->getStatusLabel($this->report->status),
                'action_url' => "/reports/{$this->report->id}",
            ]),
            'forwarded' => array_merge($data, [
                'title' => 'Anda menerima laporan yang diteruskan',
                'message' => $this->report->forward_message ?? 'Admin telah meneruskan laporan ke Anda',
                'action_url' => "/admin/reports/{$this->report->id}",
                'forwarded_by' => $this->report->forwardedByAdmin?->name,
            ]),
            'resolved' => array_merge($data, [
                'title' => 'Laporan telah diselesaikan',
                'message' => $this->report->admin_note ?? 'Laporan Anda telah diselesaikan oleh admin',
                'action_url' => "/reports/{$this->report->id}",
            ]),
            'action_taken' => array_merge($data, [
                'title' => 'Tindakan telah diambil',
                'message' => $this->getActionMessage($this->report->action_taken),
                'action_url' => "/reports/{$this->report->id}",
                'action_taken' => $this->report->action_taken,
            ]),
            default => $data,
        };
    }

    /**
     * Mail notification (optional)
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Update Laporan - Sumilir')
            ->line($this->toArray($notifiable)['message'])
            ->action('Lihat Detail', url($this->toArray($notifiable)['action_url']));
    }

    private function getStatusLabel(string $status): string
    {
        return match($status) {
            'pending' => 'Menunggu Peninjauan',
            'in_review' => 'Sedang Ditinjau',
            'resolved' => 'Diselesaikan',
            'dismissed' => 'Ditolak',
            default => ucfirst($status),
        };
    }

    private function getActionMessage(string $action): string
    {
        return match($action) {
            'user_suspended' => 'User terkait telah disuspend',
            'user_warned' => 'User terkait telah diberi peringatan',
            'content_deleted' => 'Konten telah dihapus',
            'content_hidden' => 'Konten telah disembunyikan',
            'merchant_suspended' => 'Merchant telah disuspend',
            default => 'Tindakan telah diambil oleh admin',
        };
    }
}
