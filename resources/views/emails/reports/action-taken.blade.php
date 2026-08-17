<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tindakan Moderasi - Sumilir</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background: #ffffff; color: #333333; line-height: 1.6; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; padding: 20px 0; border-bottom: 2px solid #058895; }
        .header img.logo { height: 40px; margin: 0 auto 10px; display: block; }
        .header h1 { color: #058895; font-size: 20px; margin: 0; font-weight: 600; }
        .header p { color: #666; margin: 5px 0 0; font-size: 14px; }
        .body { padding: 30px 0; }
        .greeting { font-size: 16px; margin-bottom: 20px; }
        .action-banner { background: #fff5f5; padding: 15px; margin: 20px 0; border-left: 4px solid #dc2626; text-align: center; }
        .action-banner .title { font-size: 16px; font-weight: bold; color: #b91c1c; }
        .report-card { background: #f9f9f9; padding: 20px; margin: 20px 0; border-left: 4px solid #058895; }
        .report-card .label { font-size: 12px; font-weight: bold; color: #666; text-transform: uppercase; margin-bottom: 4px; }
        .report-card .value { font-size: 14px; margin-bottom: 15px; }
        .admin-note { background: #fff8eb; padding: 15px; margin: 20px 0; border-left: 4px solid #f59e0b; font-size: 14px; color: #92400e; line-height: 1.6; }
        .appeal-box { background: #f0fdf4; padding: 15px; margin-top: 20px; border-left: 4px solid #16a34a; text-align: center; }
        .appeal-box p { font-size: 13px; color: #166534; margin: 0 0 15px; }
        .appeal-btn { display: inline-block; background: #058895; color: #ffffff !important; text-decoration: none; padding: 12px 24px; font-weight: bold; font-size: 14px; border-radius: 4px; }
        .footer { padding: 20px 0; border-top: 1px solid #eeeeee; font-size: 12px; color: #999999; text-align: center; }
        .contact-info { font-size: 12px; color: #999; margin-top: 30px; text-align: center; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <img src="{{ $message->embed(public_path('images/logo-sumilir.png')) }}" alt="{{ config('app.name') }} Logo" class="logo">
        <h1>Tindakan Moderasi</h1>
        <p>{{ config('app.name') }} – Pemberitahuan Resmi</p>
    </div>
    <div class="body">
        <p class="greeting">Halo, <strong>{{ $userName }}</strong>.</p>
        <p style="color:#4b5563; font-size:14px; line-height:1.7;">
            Kami ingin memberitahukan bahwa setelah melakukan peninjauan terhadap laporan yang masuk,
            tim moderasi kami telah mengambil keputusan terkait konten/akun Anda.
        </p>

        <div class="action-banner">
            <div class="title">{{ $actionLabel }}</div>
        </div>

        <div class="report-card">
            <div class="label">ID Laporan Terkait</div>
            <div class="value">#{{ $report->id }}</div>

            <div class="label">Tipe Konten</div>
            <div class="value">{{ $typeLabel }} ({{ $targetName ?? 'Konten' }})</div>

            <div class="label">Alasan Pelanggaran</div>
            <div class="value">{{ $report->reason?->reason_title ?? 'Pelanggaran ketentuan layanan' }}</div>
        </div>

        @if($adminNote)
        <div class="admin-note">
            <strong>Penjelasan dari Tim Moderasi:</strong><br><br>
            {{ $adminNote }}
        </div>
        @endif

        <div class="appeal-box">
            <p><strong>Merasa keputusan ini tidak adil?</strong> Anda dapat mengajukan sanggahan melalui halaman laporan Anda. Tim kami akan meninjau kembali keputusan ini.</p>
            <a href="{{ config('app.frontend_url', config('app.url')) }}/reports/{{ $report->id }}" class="appeal-btn">Ajukan Sanggahan</a>
        </div>

        <p class="contact-info">
            Jika Anda memiliki pertanyaan, silakan hubungi tim support kami.<br>
            Pesan ini dikirim otomatis oleh sistem moderasi {{ config('app.name') }}.
        </p>
    </div>
    <div class="footer">
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}. Semua hak dilindungi.</p>
    </div>
</div>
</body>
</html>
