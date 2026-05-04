<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Detail Laporan #{{ $report->id }}</title>
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

        .header .logo img {
            height: 50px;
            width: auto;
            margin-bottom: 8px;
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

        .section {
            margin-bottom: 16px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
        }

        .section-title {
            background: #058895;
            color: white;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 0.5px;
        }

        .section-body {
            padding: 10px 12px;
        }

        .info-row {
            display: flex;
            margin-bottom: 6px;
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 5px;
        }

        .info-row:last-child {
            margin-bottom: 0;
            border-bottom: none;
            padding-bottom: 0;
        }

        .info-label {
            font-weight: bold;
            width: 150px;
            color: #555;
            font-size: 10px;
            flex-shrink: 0;
        }

        .info-value {
            color: #222;
            font-size: 10px;
        }

        .badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .badge-pending    { background: #fef3c7; color: #92400e; }
        .badge-in_review  { background: #dbeafe; color: #1e40af; }
        .badge-resolved   { background: #d1fae5; color: #065f46; }
        .badge-dismissed  { background: #fee2e2; color: #991b1b; }
        .badge-type       { background: #e0e7ff; color: #4338ca; }

        .comment-box {
            background: #f8f9fa;
            border-left: 3px solid #058895;
            padding: 8px 10px;
            font-size: 10px;
            color: #444;
            border-radius: 2px;
        }

        .admin-note-box {
            background: #eff6ff;
            border-left: 3px solid #3b82f6;
            padding: 8px 10px;
            font-size: 10px;
            color: #1e40af;
            border-radius: 2px;
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

        .footer p { margin: 2px 0; }
    </style>
</head>
<body>

    <!-- Header -->
    <div class="header">
        @if(!empty($logoBase64))
            <div class="logo"><img src="{{ $logoBase64 }}" alt="Logo Sumilir"></div>
        @endif
        <h1>Detail Laporan Konten</h1>
        <p>Platform Marketplace SUMILIR</p>
    </div>

    <!-- Informasi Laporan -->
    <div class="section">
        <div class="section-title">Informasi Laporan</div>
        <div class="section-body">
            @php
                $typeKey = strtolower(class_basename($report->reportable_type));
                if ($typeKey === 'communitypost') $typeKey = 'post';
                elseif ($typeKey === 'postcomment') $typeKey = 'komentar';
                elseif ($typeKey === 'jasa') $typeKey = 'jasa';
                $typeLabels = ['product'=>'Produk','merchant'=>'Merchant','post'=>'Postingan','komentar'=>'Komentar','jasa'=>'Jasa','user'=>'Pengguna'];
                $typeLabel = $typeLabels[$typeKey] ?? ucfirst($typeKey);
                $statusLabel = match($report->status) {
                    'pending' => 'Menunggu',
                    'in_review' => 'Dalam Review',
                    'resolved' => 'Selesai',
                    'dismissed' => 'Ditolak',
                    default => ucfirst($report->status),
                };
                $statusBadge = match($report->status) {
                    'pending' => 'badge-pending',
                    'in_review' => 'badge-in_review',
                    'resolved' => 'badge-resolved',
                    'dismissed' => 'badge-dismissed',
                    default => 'badge-pending',
                };
            @endphp
            <div class="info-row">
                <div class="info-label">ID Laporan:</div>
                <div class="info-value">#{{ $report->id }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Status:</div>
                <div class="info-value"><span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span></div>
            </div>
            <div class="info-row">
                <div class="info-label">Tipe Konten:</div>
                <div class="info-value"><span class="badge badge-type">{{ $typeLabel }}</span></div>
            </div>
            <div class="info-row">
                <div class="info-label">Alasan Laporan:</div>
                <div class="info-value">{{ $report->reason->reason_title ?? '-' }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Tanggal Laporan:</div>
                <div class="info-value">{{ $report->created_at->format('d F Y, H:i') }} WIB</div>
            </div>
        </div>
    </div>

    <!-- Data Pelapor -->
    <div class="section">
        <div class="section-title">Data Pelapor</div>
        <div class="section-body">
            <div class="info-row">
                <div class="info-label">Nama:</div>
                <div class="info-value">{{ $report->reporter->name ?? '-' }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Email:</div>
                <div class="info-value">{{ $report->reporter->email ?? '-' }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Telepon:</div>
                <div class="info-value">{{ $report->reporter->phone ?? '-' }}</div>
            </div>
        </div>
    </div>

    <!-- Keterangan Pelapor -->
    @if($report->report_comment)
    <div class="section">
        <div class="section-title">Keterangan Tambahan Pelapor</div>
        <div class="section-body">
            <div class="comment-box">{{ $report->report_comment }}</div>
        </div>
    </div>
    @endif

    <!-- Riwayat Review -->
    @if($report->reviewer || $report->admin_note || $report->reviewed_at)
    <div class="section">
        <div class="section-title">Riwayat Peninjauan Admin</div>
        <div class="section-body">
            @if($report->reviewer)
            <div class="info-row">
                <div class="info-label">Direview Oleh:</div>
                <div class="info-value">{{ $report->reviewer->name }}</div>
            </div>
            @endif
            @if($report->reviewed_at)
            <div class="info-row">
                <div class="info-label">Tanggal Review:</div>
                <div class="info-value">{{ $report->reviewed_at->format('d F Y, H:i') }} WIB</div>
            </div>
            @endif
            @if($report->admin_note)
            <div style="margin-top: 6px;">
                <div class="info-label" style="margin-bottom: 4px;">Catatan Admin:</div>
                <div class="admin-note-box">{{ $report->admin_note }}</div>
            </div>
            @endif
        </div>
    </div>
    @endif

    <!-- Metadata Export -->
    <div class="section">
        <div class="section-title">Informasi Dokumen</div>
        <div class="section-body">
            <div class="info-row">
                <div class="info-label">Dicetak Tanggal:</div>
                <div class="info-value">{{ $metadata['generated_at'] }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Dicetak Oleh:</div>
                <div class="info-value">{{ $metadata['generated_by'] }} ({{ $metadata['generated_by_email'] }})</div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p><strong>© {{ date('Y') }} SUMILIR - Marketplace UMKM Banyuanyar</strong></p>
        <p>Dokumen ini dibuat secara otomatis oleh sistem pada {{ $metadata['generated_at'] }}</p>
    </div>
</body>
</html>
