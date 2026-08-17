<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Laporan Anda - Sumilir</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background: #ffffff; color: #333333; line-height: 1.6; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; padding: 20px 0; border-bottom: 2px solid #058895; }
        .header img.logo { height: 40px; margin: 0 auto 10px; display: block; }
        .header h1 { color: #058895; font-size: 20px; margin: 0; font-weight: 600; }
        .header p { color: #666; margin: 5px 0 0; font-size: 14px; }
        .body { padding: 30px 0; }
        .greeting { font-size: 16px; margin-bottom: 20px; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: bold; margin: 8px 0; }
        .status-in_review { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .status-resolved  { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .status-dismissed { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }
        .report-card { background: #f9f9f9; padding: 20px; margin: 20px 0; border-left: 4px solid #058895; }
        .report-card .label { font-size: 12px; font-weight: bold; color: #666; text-transform: uppercase; margin-bottom: 4px; }
        .report-card .value { font-size: 14px; margin-bottom: 15px; }
        .admin-note { background: #f0f9ff; padding: 15px; margin-top: 20px; border-left: 4px solid #0284c7; font-size: 14px; color: #075985; line-height: 1.6; }
        .cta-wrapper { margin: 30px 0; text-align: center; }
        .cta-btn { display: inline-block; background: #058895; color: #ffffff !important; text-decoration: none; padding: 12px 24px; font-weight: bold; font-size: 14px; border-radius: 4px; }
        .footer { padding: 20px 0; border-top: 1px solid #eeeeee; font-size: 12px; color: #999999; text-align: center; }
        .footer a { color: #058895; text-decoration: none; }
        .contact-info { font-size: 12px; color: #999; margin-top: 30px; text-align: center; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <img src="{{ $message->embed(public_path('images/logo-sumilir.png')) }}" alt="{{ config('app.name') }} Logo" class="logo">
        @if($status === 'in_review')
            <h1>Laporan Sedang Ditinjau</h1>
        @elseif($status === 'resolved')
            <h1>Laporan Telah Diselesaikan</h1>
        @elseif($status === 'dismissed')
            <h1>Laporan Ditolak</h1>
        @else
            <h1>Update Laporan Anda</h1>
        @endif
        <p>{{ config('app.name') }} – Laporan #{{ $report->id }}</p>
    </div>
    <div class="body">
        <p class="greeting">Halo, <strong>{{ $userName }}</strong>!</p>

        @if($status === 'in_review')
        <p style="color:#4b5563; font-size:14px; line-height:1.7;">
            Laporan yang Anda ajukan <strong>#{{ $report->id }}</strong> sedang <strong>dalam peninjauan</strong> oleh tim moderasi kami.
            Kami akan menginformasikan hasilnya setelah proses peninjauan selesai.
        </p>
        @elseif($status === 'resolved')
        <p style="color:#4b5563; font-size:14px; line-height:1.7;">
            Laporan yang Anda ajukan <strong>#{{ $report->id }}</strong> telah <strong>diselesaikan</strong>.
            Tim moderasi kami telah mengambil tindakan yang diperlukan.
        </p>
        @elseif($status === 'dismissed')
        <p style="color:#4b5563; font-size:14px; line-height:1.7;">
            Setelah ditinjau, laporan <strong>#{{ $report->id }}</strong> Anda <strong>tidak dapat diproses</strong>
            karena tidak memenuhi kriteria pelanggaran yang kami tetapkan.
        </p>
        @endif

        <div class="report-card">
            <div class="label">ID Laporan</div>
            <div class="value">#{{ $report->id }}</div>

            <div class="label">Tipe Konten</div>
            <div class="value">{{ $typeLabel }} ({{ $targetName ?? 'Konten' }})</div>

            <div class="label">Alasan Awal</div>
            <div class="value">{{ $report->reason?->reason_title ?? '-' }}</div>

            <div class="label">Status</div>
            <div class="value">
                <span class="status-badge status-{{ $status }}">
                    @if($status === 'in_review') Sedang Ditinjau
                    @elseif($status === 'resolved') Diselesaikan
                    @elseif($status === 'dismissed') Ditolak
                    @else {{ ucfirst($status) }}
                    @endif
                </span>
            </div>

            @if($actionTaken && $actionTaken !== 'none')
            <div class="label">Tindakan yang Diambil</div>
            <div class="value">{{ $actionLabel }}</div>
            @endif
        </div>

        @if($adminNote)
        <div class="admin-note">
            <strong>Catatan Tim Moderasi:</strong><br>
            {{ $adminNote }}
        </div>
        @endif

        <div class="cta-wrapper">
            <a href="{{ $reportUrl }}" class="cta-btn">Lihat Detail Laporan</a>
        </div>

        <p class="contact-info">
            Jika Anda memiliki pertanyaan, silakan hubungi tim kami.<br>
            <a href="{{ $reportUrl }}" style="color:#058895; font-size:11px; word-break:break-all;">{{ $reportUrl }}</a>
        </p>
    </div>
    <div class="footer">
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}. Semua hak dilindungi.</p>
    </div>
</div>
</body>
</html>
