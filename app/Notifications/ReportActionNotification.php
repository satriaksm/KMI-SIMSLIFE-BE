<?php

namespace App\Notifications;

use App\Models\ContentReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Kirim ke TERLAPOR (user/merchant owner) saat tindakan diambil admin.
 */
class ReportActionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected ContentReport $report;
    protected string $actionTaken;
    protected string $adminNote;

    public function __construct(ContentReport $report, string $actionTaken, string $adminNote = '')
    {
        $this->report     = $report;
        $this->actionTaken = $actionTaken;
        $this->adminNote  = $adminNote;
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $actionLabel = $this->getActionLabel();
        $typeLabel   = $this->getTypeLabel();

        return (new MailMessage)
            ->subject('[Tindakan Moderasi] ' . $actionLabel . ' - Sumilir Marketplace')
            ->view('emails.reports.action-taken', [
                'report'      => $this->report,
                'actionTaken' => $this->actionTaken,
                'actionLabel' => $actionLabel,
                'typeLabel'   => $typeLabel,
                'adminNote'   => $this->adminNote,
                'userName'    => $notifiable->name,
                'targetName'  => $this->report->getTargetName(),
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'report_id'    => $this->report->id,
            'type'         => 'action_taken',
            'title'        => 'Konten Anda terkena tindakan moderasi',
            'message'      => $this->getActionLabel() . ' - ' . ($this->adminNote ?: 'Pelanggaran terhadap ketentuan layanan'),
            'action_taken' => $this->actionTaken,
            'action_url'   => '/reports/' . $this->report->id,
            'created_at'   => now()->toIso8601String(),
        ];
    }

    public function getActionLabel(): string
    {
        return match ($this->actionTaken) {
            'send_warning'      => 'Peringatan Pelanggaran Konten',
            'warn_user'         => 'Anda mendapatkan peringatan',
            'suspend_user'      => 'Akun Anda telah disuspend',
            'deactivate_user'   => 'Akun Anda telah dinonaktifkan',
            'warn_merchant'     => 'UMKM Anda mendapatkan peringatan',
            'suspend_merchant'  => 'UMKM Anda telah disuspend',
            'archive_merchant'  => 'UMKM Anda telah diarsipkan',
            'archive_product'   => 'Produk Anda telah diarsipkan',
            'archive_service'   => 'Jasa Anda telah diarsipkan',
            'delete_post'       => 'Postingan Anda telah dihapus',
            'delete_comment'    => 'Komentar Anda telah dihapus',
            default             => 'Tindakan moderasi telah diambil',
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
            'user'          => 'Akun',
            default         => ucfirst($type),
        };
    }
}
