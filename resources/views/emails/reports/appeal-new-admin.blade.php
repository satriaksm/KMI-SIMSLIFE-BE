<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sanggahan Baru - Sumilir</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background: #ffffff; color: #333333; line-height: 1.6; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; padding: 20px 0; border-bottom: 2px solid #058895; }
        .header img.logo { height: 40px; margin: 0 auto 10px; display: block; }
        .header h1 { color: #058895; font-size: 20px; margin: 0; font-weight: 600; }
        .header p { color: #666; margin: 5px 0 0; font-size: 14px; }
        .body { padding: 30px 0; }
        .appeal-card { background: #f9f9f9; padding: 20px; margin: 20px 0; border-left: 4px solid #058895; }
        .appeal-card .label { font-size: 12px; font-weight: bold; color: #666; text-transform: uppercase; margin-bottom: 4px; }
        .appeal-card .value { font-size: 14px; margin-bottom: 15px; }
        .appeal-text { background: #ffffff; border: 1px solid #eeeeee; padding: 15px; font-size: 14px; color: #333; line-height: 1.6; font-style: italic; margin-top: 5px; }
        .cta-wrapper { margin: 30px 0; text-align: center; }
        .cta-btn { display: inline-block; background: #058895; color: #ffffff !important; text-decoration: none; padding: 12px 24px; font-weight: bold; font-size: 14px; border-radius: 4px; }
        .footer { padding: 20px 0; border-top: 1px solid #eeeeee; font-size: 12px; color: #999999; text-align: center; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <img src="{{ $message->embed(public_path('images/logo-sumilir.png')) }}" alt="{{ config('app.name') }} Logo" class="logo">
        <h1>Sanggahan Baru Masuk</h1>
        <p>{{ config('app.name') }} – Sistem Moderasi | Laporan #{{ $report->id }}</p>
    </div>
    <div class="body">
        <p style="color:#374151; font-size:15px;">Halo, <strong>{{ $adminName }}</strong>!</p>
        <p style="color:#4b5563; font-size:14px; line-height:1.7;">
            Pengguna <strong>{{ $appellantName }}</strong> telah mengajukan sanggahan terhadap
            tindakan moderasi pada Laporan <strong>#{{ $report->id }}</strong>.
        </p>

        <div class="appeal-card">
            <div class="label">Diajukan oleh</div>
            <div class="value">{{ $appellantName }} &lt;{{ $appeal->appellant?->email ?? '-' }}&gt;</div>

            <div class="label">Terkait Laporan</div>
            <div class="value">#{{ $report->id }} – {{ $typeLabel }}</div>

            <div class="label">Isi Sanggahan</div>
            <div class="appeal-text">"{{ $appeal->appeal_text }}"</div>
        </div>

        <div class="cta-wrapper">
            <a href="{{ $reviewUrl }}" class="cta-btn">Tinjau Sanggahan</a>
        </div>
    </div>
    <div class="footer">
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}.</p>
    </div>
</div>
</body>
</html>
