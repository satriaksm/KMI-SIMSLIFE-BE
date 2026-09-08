<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil Sanggahan - Sumilir</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background: #ffffff; color: #333333; line-height: 1.6; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; padding: 20px 0; border-bottom: 2px solid #058895; }
        .header img.logo { height: 40px; margin: 0 auto 10px; display: block; }
        .header h1 { color: #058895; font-size: 20px; margin: 0; font-weight: 600; }
        .header p { color: #666; margin: 5px 0 0; font-size: 14px; }
        .body { padding: 30px 0; }
        .result-card { background: #f9f9f9; padding: 20px; margin: 20px 0; border-left: 4px solid #058895; }
        .result-accepted { border-left-color: #10b981; }
        .result-rejected  { border-left-color: #ef4444; }
        .result-card .label { font-size: 12px; font-weight: bold; color: #666; text-transform: uppercase; margin-bottom: 4px; }
        .result-card .value { font-size: 16px; font-weight: bold; margin-bottom: 15px; }
        .admin-response { background: #f0f9ff; padding: 15px; margin-top: 20px; border-left: 4px solid #0284c7; font-size: 14px; color: #075985; line-height: 1.6; }
        .footer { padding: 20px 0; border-top: 1px solid #eeeeee; font-size: 12px; color: #999999; text-align: center; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <img src="{{ $message->embed(public_path('images/logo-sumilir.png')) }}" alt="{{ config('app.name') }} Logo" class="logo">
        @if($appeal->status === 'accepted')
            <h1>Sanggahan Diterima</h1>
        @else
            <h1>Sanggahan Ditolak</h1>
        @endif
        <p>{{ config('app.name') }} – Laporan #{{ $report->id }}</p>
    </div>
    <div class="body">
        <p style="color:#374151; font-size:15px;">Halo, <strong>{{ $userName }}</strong>!</p>
        <p style="color:#4b5563; font-size:14px; line-height:1.7;">
            Sanggahan yang Anda ajukan terhadap tindakan moderasi pada Laporan <strong>#{{ $report->id }}</strong>
            telah ditinjau oleh tim kami.
        </p>

        <div class="result-card {{ $appeal->status === 'accepted' ? 'result-accepted' : 'result-rejected' }}">
            <div class="label">Hasil Peninjauan Sanggahan</div>
            <div class="value" style="color:{{ $appeal->status === 'accepted' ? '#10b981' : '#ef4444' }}">
                {{ $appeal->status === 'accepted' ? 'Sanggahan Diterima' : 'Sanggahan Ditolak' }}
            </div>

            @if($appeal->admin_response)
            <div class="admin-response">
                <strong>Respons Tim Moderasi:</strong><br><br>
                {{ $appeal->admin_response }}
            </div>
            @endif
        </div>

        @if($appeal->status === 'accepted')
        <p style="color:#4b5563; font-size:14px; line-height:1.7;">
            Kami akan meninjau kembali tindakan yang telah diambil. Jika perlu, kami akan memulihkan
            konten atau status akun Anda sesuai prosedur yang berlaku.
        </p>
        @else
        <p style="color:#4b5563; font-size:14px; line-height:1.7;">
            Setelah ditinjau ulang, keputusan moderasi tetap berlaku. Kami mengharapkan Anda
            untuk mematuhi Ketentuan Layanan {{ config('app.name') }} ke depannya.
        </p>
        @endif

    </div>
    <div class="footer">
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}. Semua hak dilindungi.</p>
    </div>
</div>
</body>
</html>
