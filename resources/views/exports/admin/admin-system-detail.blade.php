<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Detail Admin - {{ $admin->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Arial', sans-serif; font-size: 10pt; color: #333; }
        .header { text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #058895; }
        .header img { height: 50px; margin-bottom: 10px; }
        .header h1 { font-size: 18pt; color: #058895; margin-bottom: 5px; }
        .header p { font-size: 9pt; color: #666; }
        .section { margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px; border-left: 4px solid #058895; }
        .section h2 { font-size: 12pt; color: #058895; margin-bottom: 10px; }
        .info-grid { display: table; width: 100%; }
        .info-row { display: table-row; }
        .info-label { display: table-cell; font-weight: bold; color: #058895; width: 180px; padding: 6px 0; }
        .info-value { display: table-cell; padding: 6px 0; }
        .badge { padding: 4px 8px; border-radius: 3px; font-size: 8pt; font-weight: 600; display: inline-block; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-suspended { background: #f8d7da; color: #721c24; }
        .badge-system { background: #e7d9f7; color: #6f42c1; }
        .badge-regular { background: #cfe2ff; color: #084298; }
        table.data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.data-table th { background: #058895; color: white; padding: 8px; text-align: left; font-size: 9pt; }
        table.data-table td { padding: 8px; border-bottom: 1px solid #e0e0e0; font-size: 9pt; }
        table.data-table tr:nth-child(even) { background: #f8f9fa; }
        .footer { margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd; text-align: center; font-size: 8pt; color: #666; }
        .metadata-box { background: #e3f2fd; padding: 10px; margin-bottom: 15px; border-radius: 4px; font-size: 8pt; }
    </style>
</head>
<body>
    <div class="header">
        @if($logoBase64)
            <img src="{{ $logoBase64 }}" alt="Logo">
        @endif
        <h1>Detail Admin</h1>
        <p>{{ $admin->name }}</p>
    </div>

    <div class="metadata-box">
        <strong>Dibuat oleh:</strong> {{ $metadata['generated_by'] }} ({{ $metadata['generated_by_email'] }}) | 
        <strong>Tanggal:</strong> {{ $metadata['generated_at'] }}
    </div>

    <div class="section">
        <h2>Informasi Admin</h2>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Nama</div>
                <div class="info-value">{{ $admin->name }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Email</div>
                <div class="info-value">{{ $admin->email }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Type</div>
                <div class="info-value">
                    <span class="badge {{ $admin->is_system_admin ? 'badge-system' : 'badge-regular' }}">
                        {{ $admin->is_super_admin ? 'Super Admin' : 'Admin' }}
                    </span>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Status</div>
                <div class="info-value">
                    <span class="badge {{ $admin->status === 'active' ? 'badge-active' : 'badge-suspended' }}">
                        {{ ucfirst($admin->status) }}
                    </span>
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Email Verified</div>
                <div class="info-value">{{ $admin->email_verified_at ? 'Yes' : 'No' }}</div>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Riwayat Aktivitas</h2>
        @if($activityLogs && count($activityLogs) > 0)
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 15%;">Tanggal</th>
                        <th style="width: 20%;">Tipe Aksi</th>
                        <th style="width: 35%;">Alasan</th>
                        <th style="width: 30%;">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($activityLogs as $log)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($log->created_at)->format('d/m/Y H:i') }}</td>
                            <td>{{ ucwords(str_replace('_', ' ', $log->action_type)) }}</td>
                            <td>{{ $log->reason ?? '-' }}</td>
                            <td style="font-size: 8pt;">
                                @if($log->metadata)
                                    @foreach(json_decode($log->metadata, true) as $key => $value)
                                        <div><strong>{{ ucfirst($key) }}:</strong> {{ is_array($value) ? json_encode($value) : $value }}</div>
                                    @endforeach
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p style="text-align: center; color: #666; padding: 10px;">Belum ada riwayat aktivitas</p>
        @endif
    </div>

    <div class="section">
        <h2>Timeline</h2>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Admin Created</div>
                <div class="info-value">{{ \Carbon\Carbon::parse($admin->created_at)->format('d F Y, H:i:s') }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Last Updated</div>
                <div class="info-value">{{ \Carbon\Carbon::parse($admin->updated_at)->format('d F Y, H:i:s') }}</div>
            </div>
        </div>
    </div>

    <div class="footer">
        <p>Dokumen ini digenerate secara otomatis oleh sistem SIMSLIFE</p>
        <p>© {{ date('Y') }} SIMSLIFE. All rights reserved.</p>
    </div>
</body>
</html>