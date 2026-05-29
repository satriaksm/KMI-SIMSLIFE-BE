<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Baru - Sumilir</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background: #ffffff; color: #333333; line-height: 1.6; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; padding: 20px 0; border-bottom: 2px solid #058895; }
        .header img.logo { height: 40px; margin: 0 auto 10px; display: block; }
        .header h1 { color: #058895; font-size: 20px; margin: 0; font-weight: 600; }
        .header p { color: #666; margin: 5px 0 0; font-size: 14px; }
        .body { padding: 30px 0; }
        .greeting { font-size: 16px; margin-bottom: 20px; }
        .report-card { background: #f9f9f9; padding: 20px; margin: 20px 0; border-left: 4px solid #058895; }
        .report-card .label { font-size: 12px; font-weight: bold; color: #666; text-transform: uppercase; margin-bottom: 4px; }
        .report-card .value { font-size: 14px; margin-bottom: 15px; }
        .report-card .value:last-child { margin-bottom: 0; }
        .cta-wrapper { margin: 30px 0; text-align: center; }
        .cta-btn { display: inline-block; background: #058895; color: #ffffff !important; text-decoration: none; padding: 12px 24px; font-weight: bold; font-size: 14px; border-radius: 4px; }
        .footer { padding: 20px 0; border-top: 1px solid #eeeeee; font-size: 12px; color: #999999; text-align: center; }
        .footer a { color: #058895; text-decoration: none; }
        .urgent-note { background: #fff8eb; padding: 15px; margin-top: 20px; border-left: 4px solid #f59e0b; font-size: 14px; color: #92400e; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <img src="{{ $message->embed(public_path('images/logo-sumilir.png')) }}" alt="{{ config('app.name') }} Logo" class="logo">
        <h1>Laporan Baru Masuk</h1>
        <p>{{ config('app.name') }} – Sistem Moderasi</p>
    </div>
    <div class="body">
        <p class="greeting">Halo, <strong>{{ $adminName }}</strong>!</p>
        <p style="color:#4b5563; font-size:14px; line-height:1.7;">
            Ada laporan konten baru yang perlu Anda tinjau. Berikut detail laporan yang masuk:
        </p>

        <div class="report-card">
            <div class="label">ID Laporan</div>
            <div class="value"><span class="highlight">#{{ $report->id }}</span></div>

            <div class="label">Tipe Konten Dilaporkan</div>
            <div class="value">{{ $typeLabel }} ({{ $targetName ?? 'Konten' }})</div>

            <div class="label">Alasan Laporan</div>
            <div class="value">{{ $reasonTitle }}</div>

            <div class="label">Dilaporkan oleh</div>
            <div class="value">{{ $reporterName }} &lt;{{ $report->reporter?->email ?? '-' }}&gt;</div>

            @if($report->report_comment)
            <div class="label">Keterangan Pelapor</div>
            <div class="value" style="font-style:italic; color:#6b7280;">"{{ $report->report_comment }}"</div>
            @endif

            <div class="label">Waktu Laporan</div>
            <div class="value">{{ $report->created_at->format('d F Y, H:i') }} WIB</div>
        </div>

        <div class="urgent-note">
            Mohon segera tinjau laporan ini untuk menjaga kualitas marketplace.
        </div>

        <div class="cta-wrapper">
            <a href="{{ $reportUrl }}" class="cta-btn">Tinjau Laporan Sekarang</a>
        </div>

        <hr class="divider">
        <p style="font-size:12px; color:#94a3b8; text-align:center;">
            Atau salin link berikut ke browser Anda:<br>
            <a href="{{ $reportUrl }}" style="color:#4f46e5; font-size:11px; word-break:break-all;">{{ $reportUrl }}</a>
        </p>
    </div>
    <div class="footer">
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}. Semua hak dilindungi.</p>
        <p>Email ini dikirim otomatis oleh sistem moderasi {{ config('app.name') }}.</p>
    </div>
</div>
</body>
</html>
