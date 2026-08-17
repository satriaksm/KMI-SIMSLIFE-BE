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
        return ['database', 'mail'];
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
        $data       = $this->toArray($notifiable);
        $reportUrl  = config('app.frontend_url', config('app.url')) . '/reports/' . $this->report->id;
        $typeLabel  = $this->getTypeLabel();
        $actionLabel = isset($this->report->action_taken)
            ? $this->getActionMessage($this->report->action_taken ?? '')
            : null;

        return (new MailMessage)
            ->subject($this->getMailSubject())
            ->view('emails.reports.status-update', [
                'report'      => $this->report,
                'status'      => $this->report->status,
                'typeLabel'   => $typeLabel,
                'adminNote'   => $this->report->admin_note,
                'actionTaken' => $this->report->action_taken ?? 'none',
                'actionLabel' => $actionLabel,
                'reportUrl'   => $reportUrl,
                'userName'    => $notifiable->name,
                'targetName'  => $this->report->getTargetName(),
            ]);
    }

    private function getMailSubject(): string
    {
        return match ($this->type) {
            'status_changed' => '[Update Laporan #' . $this->report->id . '] ' . $this->getStatusLabel($this->report->status) . ' - Sumilir',
            'resolved'       => '[Laporan Selesai #' . $this->report->id . '] - Sumilir',
            'action_taken'   => '[Update Laporan #' . $this->report->id . '] Tindakan Telah Diambil - Sumilir',
            default          => 'Update Laporan #' . $this->report->id . ' - Sumilir',
        };
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
            'user'          => 'Akun Pengguna',
            default         => ucfirst($type),
        };
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
            'send_warning' => 'Pengguna terkait telah dikirimkan Peringatan Pelanggaran',
            'suspend_user' => 'Pengguna terkait telah disuspend',
            'warn_user' => 'Pengguna terkait telah diberi peringatan',
            'deactivate_user' => 'Pengguna terkait dinonaktifkan',
            'warn_merchant' => 'UMKM terkait telah diberi peringatan',
            'suspend_merchant' => 'UMKM terkait telah disuspend',
            'archive_merchant' => 'UMKM terkait telah diarsipkan',
            'archive_product' => 'Produk telah diarsipkan',
            'archive_service' => 'Jasa telah diarsipkan',
            'delete_post' => 'Postingan telah dihapus',
            'delete_comment' => 'Komentar telah dihapus',
            default => 'Tindakan telah diambil oleh admin',
        };
    }
}
