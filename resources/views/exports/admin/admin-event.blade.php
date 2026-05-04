<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Daftar Event</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #333; line-height: 1.4; }
        .header { text-align: center; margin-bottom: 25px; border-bottom: 2px solid #f3f4f6; padding-bottom: 15px; }
        .logo { max-height: 50px; margin-bottom: 8px; }
        .title { font-size: 18px; font-weight: bold; color: #111; margin: 0; }
        .metadata { font-size: 9px; color: #666; margin-top: 4px; }
        
        .filter-info { margin-bottom: 15px; padding: 10px; background: #f9fafb; border-radius: 6px; }
        .filter-item { display: inline-block; margin-right: 20px; }
        .filter-label { font-weight: bold; color: #666; }
        
        table { width: 100%; border-collapse: collapse; }
        th { background-color: #f3f4f6; color: #374151; font-weight: bold; text-align: left; padding: 8px; border-bottom: 1px solid #d1d5db; }
        td { padding: 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        
        .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .badge-published { background-color: #dcfce7; color: #166534; }
        .badge-draft { background-color: #f3f4f6; color: #374151; }
        .badge-archived { background-color: #fee2e2; color: #991b1b; }
        
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 9px; color: #999; border-top: 1px solid #eee; padding-top: 8px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        @if($logoBase64)
            <img src="{{ $logoBase64 }}" class="logo" alt="Logo">
        @endif
        <h1 class="title">Laporan Daftar Event</h1>
        <div class="metadata">
            Dicetak pada: {{ $metadata['generated_at'] }} | Oleh: {{ $metadata['generated_by'] }}
        </div>
    </div>

    <div class="filter-info">
        <div class="filter-item"><span class="filter-label">Filter Status:</span> {{ $metadata['filters']['status'] }}</div>
        <div class="filter-item"><span class="filter-label">Pencarian:</span> {{ $metadata['filters']['search'] }}</div>
        <div class="filter-item"><span class="filter-label">Total Data:</span> {{ $metadata['total_events'] }} Event</div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th style="width: 25%;">Nama Event</th>
                <th style="width: 15%;">Tgl Mulai</th>
                <th style="width: 15%;">Tgl Selesai</th>
                <th style="width: 10%;">Status</th>
                <th style="width: 10%;" class="text-center">Merchant</th>
                <th style="width: 10%;" class="text-center">Voucher</th>
                <th style="width: 10%;">Dibuat Oleh</th>
            </tr>
        </thead>
        <tbody>
            @foreach($events as $index => $event)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td><strong>{{ $event->event_name }}</strong></td>
                    <td>{{ $event->event_start_date->format('d/m/Y') }}</td>
                    <td>{{ $event->event_end_date->format('d/m/Y') }}</td>
                    <td>
                        <span class="badge badge-{{ $event->status }}">
                            {{ strtoupper($event->status) }}
                        </span>
                    </td>
                    <td class="text-center">{{ $event->merchants_count }}</td>
                    <td class="text-center">{{ $event->vouchers_count }}</td>
                    <td>{{ $event->creator->name ?? '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        SUMILIR - Digital Ecosystem for UMKM | Laporan Daftar Event
    </div>
</body>
</html>