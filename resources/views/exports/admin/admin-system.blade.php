<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Laporan Data Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Arial', sans-serif; font-size: 10pt; color: #333; }
        .header { text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #058895; }
        .header img { height: 50px; margin-bottom: 10px; }
        .header h1 { font-size: 18pt; color: #058895; margin-bottom: 5px; }
        .header p { font-size: 9pt; color: #666; }
        .info-box { background: #f8f9fa; padding: 12px; margin-bottom: 15px; border-radius: 4px; border-left: 4px solid #058895; }
        .info-box table { width: 100%; border-collapse: collapse; }
        .info-box td { padding: 4px 8px; font-size: 9pt; }
        .info-box td:first-child { font-weight: bold; color: #058895; width: 180px; }
        table.data-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        table.data-table th { background: #058895; color: white; padding: 10px 8px; text-align: left; font-size: 9pt; font-weight: 600; }
        table.data-table td { padding: 8px; border-bottom: 1px solid #e0e0e0; font-size: 9pt; }
        table.data-table tr:nth-child(even) { background: #f8f9fa; }
        .badge { padding: 4px 8px; border-radius: 3px; font-size: 8pt; font-weight: 600; display: inline-block; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-suspended { background: #f8d7da; color: #721c24; }
        .badge-system { background: #e7d9f7; color: #6f42c1; }
        .badge-regular { background: #cfe2ff; color: #084298; }
        .footer { margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd; text-align: center; font-size: 8pt; color: #666; }
        .text-center { text-align: center; }
        .text-muted { color: #666; }
    </style>
</head>
<body>
    <div class="header">
        @if($logoBase64)
            <img src="{{ $logoBase64 }}" alt="Logo">
        @endif
        <h1>Laporan Data Admin</h1>
        <p>Sistem Informasi Manajemen SIMSLIFE</p>
    </div>

    <div class="info-box">
        <table>
            <tr>
                <td>Dibuat Oleh</td>
                <td>: {{ $metadata['generated_by'] ?? '-' }} ({{ $metadata['generated_by_email'] ?? '-' }})</td>
            </tr>
            <tr>
                <td>Tanggal & Waktu</td>
                <td>: {{ $metadata['generated_at'] ?? '-' }}</td>
            </tr>
            <tr>
                <td>Total Admin</td>
                <td>: {{ $metadata['total_admins'] ?? 0 }}</td>
            </tr>
            <tr>
                <td>Filter Status</td>
                <td>: {{ $metadata['filters']['status'] ?? 'Semua' }}</td>
            </tr>
            <tr>
                <td>Pencarian</td>
                <td>: {{ $metadata['filters']['search'] ?? '-' }}</td>
            </tr>
        </table>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 25%;">Nama</th>
                <th style="width: 30%;">Email</th>
                <th style="width: 20%;">Type</th>
                <th style="width: 20%;">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($admins as $index => $admin)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $admin->name ?? '-' }}</td>
                    <td>{{ $admin->email ?? '-' }}</td>
                    <td>
                        <span class="badge {{ $admin->is_super_admin ? 'badge-system' : 'badge-regular' }}">
                            {{ $admin->is_super_admin ? 'Super Admin' : 'Admin' }}
                        </span>
                    </td>
                    <td>
                        <span class="badge {{ $admin->status === 'active' ? 'badge-active' : 'badge-suspended' }}">
                            {{ ucfirst($admin->status) }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">Tidak ada data admin</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <p>Dokumen ini digenerate secara otomatis oleh sistem SIMSLIFE</p>
        <p>© {{ date('Y') }} SIMSLIFE. All rights reserved.</p>
    </div>
</body>
</html>