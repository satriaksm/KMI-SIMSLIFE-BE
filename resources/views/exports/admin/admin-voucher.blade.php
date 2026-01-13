<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Dashboard Admin</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            margin: 0;
            padding: 15px;
            padding-bottom: 50px; /* ✅ Space for fixed footer */
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
            width: 120px;
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

        .badge-active {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-inactive {
            background: #e5e7eb;
            color: #4b5563;
        }

        .badge-expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-percent {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-fixed {
            background: #fef3c7;
            color: #92400e;
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

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
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
        <h1>Laporan Data Voucher</h1>
        <p>Daftar Voucher - Platform Marketplace SUMILIR</p>
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
        <h3>Total Voucher: {{ $metadata['total_vouchers'] }}</h3>
    </div>

    <!-- Vouchers Table -->
    @if(count($vouchers) > 0)
        <table>
            <thead>
                <tr>
                    <th style="width: 3%;">No</th>
                    <th style="width: 12%;">Kode</th>
                    <th style="width: 20%;">Deskripsi</th>
                    <th style="width: 8%;">Tipe</th>
                    <th style="width: 8%;">Nilai</th>
                    <th style="width: 10%;">Min. Beli</th>
                    <th style="width: 8%;">Limit</th>
                    <th style="width: 8%;">Digunakan</th>
                    <th style="width: 15%;">Event</th>
                    <th style="width: 8%;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($vouchers as $index => $voucher)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td><strong>{{ $voucher->voucher_code }}</strong></td>
                        <td>{{ $voucher->voucher_description ?? '-' }}</td>
                        <td>
                            @php
                                $typeBadgeClass = $voucher->voucher_type === 'percent' ? 'badge-percent' : 'badge-fixed';
                            @endphp
                            <span class="badge {{ $typeBadgeClass }}">
                                {{ $voucher->voucher_type === 'percent' ? 'Persentase' : 'Nominal' }}
                            </span>
                        </td>
                        <td class="text-right">
                            <strong>
                                {{ $voucher->voucher_type === 'percent' 
                                    ? $voucher->value . '%' 
                                    : 'Rp ' . number_format($voucher->value, 0, ',', '.') 
                                }}
                            </strong>
                        </td>
                        <td class="text-right">Rp {{ number_format($voucher->min_purchase_amount ?? 0, 0, ',', '.') }}</td>
                        <td class="text-center">{{ $voucher->usage_limit ?? '∞' }}</td>
                        <td class="text-center">{{ $voucher->usages_count ?? 0 }}</td>
                        <td>
                            @if($voucher->event)
                                <span style="font-size: 8px;">{{ $voucher->event->event_name }}</span>
                            @else
                                -
                            @endif
                        </td>
                        <td>
                            @php
                                $statusBadgeClass = match(strtolower($voucher->voucher_status ?? 'active')) {
                                    'active' => 'badge-active',
                                    'inactive' => 'badge-inactive',
                                    'expired' => 'badge-expired',
                                    default => 'badge-inactive'
                                };
                            @endphp
                            <span class="badge {{ $statusBadgeClass }}">
                                {{ ucfirst($voucher->voucher_status ?? 'active') }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="no-data">Tidak ada data voucher yang ditemukan</div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <p><strong>© {{ date('Y') }} SUMILIR - Marketplace UMKM Banyuanyar</strong></p>
        <p>Laporan ini dibuat secara otomatis oleh sistem pada {{ $metadata['generated_at'] }}</p>
    </div>
</body>
</html>