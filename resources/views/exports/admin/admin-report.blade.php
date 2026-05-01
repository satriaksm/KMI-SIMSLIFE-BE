<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Data Pelaporan Konten</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            margin: 0;
            padding: 15px;
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 3px solid #058895;
        }

        .header .logo {
            margin-bottom: 8px;
        }

        .header .logo img {
            height: 50px;
            width: auto;
        }

        .header h1 {
            margin: 0 0 3px 0;
            font-size: 20px;
            color: #058895;
        }

        .header p {
            margin: 0;
            font-size: 10px;
            color: #666;
        }

        .metadata {
            background: #f8f9fa;
            padding: 8px 10px;
            margin-bottom: 15px;
            border-radius: 4px;
            border-left: 4px solid #058895;
        }

        .metadata-row {
            display: flex;
            margin-bottom: 3px;
        }

        .metadata-row:last-child {
            margin-bottom: 0;
        }

        .metadata-label {
            font-weight: bold;
            width: 130px;
            color: #555;
            font-size: 10px;
        }

        .metadata-value {
            color: #333;
            font-size: 10px;
        }

        .summary {
            background: #e0f7fa;
            border: 1px solid #058895;
            border-radius: 4px;
            padding: 8px 10px;
            margin-bottom: 15px;
            text-align: center;
        }

        .summary h3 {
            margin: 0 0 5px 0;
            color: #058895;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background: #058895;
            color: white;
            padding: 6px 4px;
            text-align: left;
            font-size: 10px;
            font-weight: bold;
            border: 1px solid #047885;
        }

        td {
            padding: 5px 4px;
            border: 1px solid #ddd;
            font-size: 9px;
            vertical-align: top;
        }

        tr:nth-child(even) {
            background: #f9f9f9;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-in_review {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-resolved {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-dismissed {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-type {
            background: #e0e7ff;
            color: #4338ca;
        }

        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 10px 15px;
            border-top: 2px solid #e0e0e0;
            text-align: center;
            font-size: 8px;
            color: #999;
            background: white;
        }

        .footer p {
            margin: 2px 0;
        }

        .no-data {
            text-align: center;
            color: #999;
            padding: 30px;
            font-style: italic;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        @if(!empty($logoBase64))
            <div class="logo">
                <img src="{{ $logoBase64 }}" alt="Logo Sumilir">
            </div>
        @endif
        <h1>Laporan Data Pelaporan Konten</h1>
        <p>Daftar Laporan Konten - Platform Marketplace SUMILIR</p>
    </div>

    <!-- Metadata -->
    <div class="metadata">
        <div class="metadata-row">
            <div class="metadata-label">Dibuat Tanggal:</div>
            <div class="metadata-value">{{ $metadata['generated_at'] }}</div>
        </div>
        <div class="metadata-row">
            <div class="metadata-label">Dibuat Oleh:</div>
            <div class="metadata-value">{{ $metadata['generated_by'] }} ({{ $metadata['generated_by_email'] }})</div>
        </div>
        <div class="metadata-row">
            <div class="metadata-label">Filter Status:</div>
            <div class="metadata-value">{{ $metadata['filters']['status'] }}</div>
        </div>
        <div class="metadata-row">
            <div class="metadata-label">Filter Tipe:</div>
            <div class="metadata-value">{{ $metadata['filters']['type'] }}</div>
        </div>
        @if($metadata['filters']['search'] !== '-')
        <div class="metadata-row">
            <div class="metadata-label">Pencarian:</div>
            <div class="metadata-value">{{ $metadata['filters']['search'] }}</div>
        </div>
        @endif
    </div>

    <!-- Summary -->
    <div class="summary">
        <h3>Total Laporan: {{ $metadata['total_reports'] }}</h3>
    </div>

    <!-- Reports Table -->
    @if(count($reports) > 0)
        <table>
            <thead>
                <tr>
                    <th style="width: 3%;">No</th>
                    <th style="width: 8%;">ID</th>
                    <th style="width: 18%;">Pelapor</th>
                    <th style="width: 12%;">Tipe Konten</th>
                    <th style="width: 18%;">Alasan</th>
                    <th style="width: 10%;">Status</th>
                    <th style="width: 18%;">Catatan Admin</th>
                    <th style="width: 13%;">Tanggal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($reports as $index => $report)
                    @php
                        $typeKey = strtolower(class_basename($report->reportable_type));
                        if ($typeKey === 'communitypost') $typeKey = 'post';
                        elseif ($typeKey === 'postcomment') $typeKey = 'komentar';
                        elseif ($typeKey === 'jasa') $typeKey = 'jasa';

                        $typeLabels = [
                            'product' => 'Produk',
                            'merchant' => 'Merchant',
                            'post' => 'Postingan',
                            'komentar' => 'Komentar',
                            'jasa' => 'Jasa',
                            'user' => 'Pengguna',
                        ];
                        $typeLabel = $typeLabels[$typeKey] ?? ucfirst($typeKey);

                        $statusBadge = match($report->status) {
                            'pending' => 'badge-pending',
                            'in_review' => 'badge-in_review',
                            'resolved' => 'badge-resolved',
                            'dismissed' => 'badge-dismissed',
                            default => 'badge-pending',
                        };
                        $statusLabel = match($report->status) {
                            'pending' => 'Menunggu',
                            'in_review' => 'Dalam Review',
                            'resolved' => 'Selesai',
                            'dismissed' => 'Ditolak',
                            default => ucfirst($report->status),
                        };
                    @endphp
                    <tr>
                        <td style="text-align: center;">{{ $index + 1 }}</td>
                        <td>#{{ $report->id }}</td>
                        <td>{{ $report->reporter->name ?? '-' }}<br><span style="color:#888;font-size:8px;">{{ $report->reporter->email ?? '' }}</span></td>
                        <td><span class="badge badge-type">{{ $typeLabel }}</span></td>
                        <td>{{ $report->reason->reason_title ?? '-' }}</td>
                        <td><span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span></td>
                        <td>{{ $report->admin_note ?? '-' }}</td>
                        <td>{{ $report->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="no-data">Tidak ada data laporan yang ditemukan</div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <p><strong>© {{ date('Y') }} SUMILIR - Marketplace UMKM Banyuanyar</strong></p>
        <p>Laporan ini dibuat secara otomatis oleh sistem pada {{ $metadata['generated_at'] }}</p>
    </div>
</body>
</html>
