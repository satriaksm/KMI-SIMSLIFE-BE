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

        .summary-section {
            background-color: #f0f9fa;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
            border: 1px solid #b8e6ea;
        }
        
        .summary-grid {
            display: table;
            width: 100%;
        }
        
        .summary-item {
            display: table-cell;
            width: 25%;
            text-align: center;
            padding: 5px;
        }
        
        .summary-number {
            font-size: 16px;
            font-weight: bold;
            color: #058895;
            display: block;
            margin-bottom: 3px;
        }
        
        .summary-label {
            font-size: 8px;
            color: #666;
            text-transform: uppercase;
        }

        .summary-growth {
            font-size: 7px;
            font-weight: bold;
            margin-top: 2px;
        }

        .summary-growth.positive {
            color: #10b981;
        }

        .summary-growth.negative {
            color: #ef4444;
        }

        .section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }

        .section-title {
            font-size: 13px;
            font-weight: bold;
            color: #058895;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 2px solid #e0e0e0;
        }

        .chart-container {
            display: table;
            width: 100%;
            margin-top: 10px;
        }

        .chart-item {
            display: table-cell;
            width: 50%;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #ddd;
            vertical-align: top;
        }

        .chart-item:first-child {
            padding-right: 5px;
        }

        .chart-item:last-child {
            padding-left: 5px;
        }

        .chart-title {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 8px;
            color: #555;
            border-bottom: 1px solid #ddd;
            padding-bottom: 4px;
        }

        .chart-bar {
            display: flex;
            align-items: center;
            margin-bottom: 6px;
        }

        .chart-label {
            width: 100px;
            font-size: 9px;
            color: #666;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chart-bar-wrapper {
            flex: 1;
            background: #e0e0e0;
            height: 14px;
            border-radius: 3px;
            margin-right: 6px;
            position: relative;
        }

        .chart-bar-fill {
            height: 100%;
            background: #058895;
            border-radius: 3px;
            min-width: 2px;
        }

        .chart-value {
            font-size: 9px;
            font-weight: bold;
            color: #058895;
            min-width: 25px;
            text-align: right;
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

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 2px solid #e0e0e0;
            text-align: center;
            font-size: 8px;
            color: #999;
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
        <h1>Laporan Dashboard Admin</h1>
        <p>Statistik & Analisis Platform Marketplace SUMILIR</p>
    </div>

    <!-- Metadata -->
    <div class="metadata">
        <div class="metadata-row">
            <div class="metadata-label">Periode:</div>
            <div class="metadata-value">{{ $metadata['period'] }}</div>
        </div>
        <div class="metadata-row">
            <div class="metadata-label">Tanggal Rentang:</div>
            <div class="metadata-value">{{ $metadata['period_start'] }} s/d {{ $metadata['period_end'] }}</div>
        </div>
        <div class="metadata-row">
            <div class="metadata-label">Dibuat Tanggal:</div>
            <div class="metadata-value">{{ $metadata['generated_at'] }}</div>
        </div>
        <div class="metadata-row">
            <div class="metadata-label">Dibuat Oleh:</div>
            <div class="metadata-value">{{ $metadata['generated_by'] }} ({{ $metadata['generated_by_email'] }})</div>
        </div>
    </div>

    <!-- Overview Statistics -->
    <div class="section">
        <div class="section-title">Overview Statistik</div>
        <div class="summary-section">
            <div class="summary-grid">
                <div class="summary-item">
                    <span class="summary-number">{{ number_format($data['overview']['users']['current']) }}</span>
                    <span class="summary-label">Total Users</span>
                    @if($data['overview']['users']['growth'] !== null)
                        <div class="summary-growth {{ $data['overview']['users']['growth'] >= 0 ? 'positive' : 'negative' }}">
                            {{ $data['overview']['users']['growth'] >= 0 ? '▲' : '▼' }} {{ abs($data['overview']['users']['growth']) }}%
                        </div>
                    @endif
                </div>

                <div class="summary-item">
                    <span class="summary-number">{{ number_format($data['overview']['paguyubans']['current']) }}</span>
                    <span class="summary-label">Total Paguyuban</span>
                    @if($data['overview']['paguyubans']['growth'] !== null)
                        <div class="summary-growth {{ $data['overview']['paguyubans']['growth'] >= 0 ? 'positive' : 'negative' }}">
                            {{ $data['overview']['paguyubans']['growth'] >= 0 ? '▲' : '▼' }} {{ abs($data['overview']['paguyubans']['growth']) }}%
                        </div>
                    @endif
                </div>

                <div class="summary-item">
                    <span class="summary-number">{{ number_format($data['overview']['products']['current']) }}</span>
                    <span class="summary-label">Total Products</span>
                    @if($data['overview']['products']['growth'] !== null)
                        <div class="summary-growth {{ $data['overview']['products']['growth'] >= 0 ? 'positive' : 'negative' }}">
                            {{ $data['overview']['products']['growth'] >= 0 ? '▲' : '▼' }} {{ abs($data['overview']['products']['growth']) }}%
                        </div>
                    @endif
                </div>

                <div class="summary-item">
                    <span class="summary-number">{{ number_format($data['overview']['pending_reports']['current']) }}</span>
                    <span class="summary-label">Pending Reports</span>
                    @if($data['overview']['pending_reports']['growth'] !== null)
                        <div class="summary-growth {{ $data['overview']['pending_reports']['growth'] >= 0 ? 'positive' : 'negative' }}">
                            {{ $data['overview']['pending_reports']['growth'] >= 0 ? '▲' : '▼' }} {{ abs($data['overview']['pending_reports']['growth']) }}%
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Distribution Charts -->
    <div class="section">
        <div class="section-title">Distribusi Data</div>
        <div class="chart-container">
            <!-- Products by Category -->
            <div class="chart-item">
                <div class="chart-title">Produk per Kategori</div>
                @php
                    $maxProductCount = collect($data['products']['by_category'])->max('count') ?: 1;
                @endphp
                @if(count($data['products']['by_category']) > 0)
                    @foreach($data['products']['by_category'] as $category)
                        <div class="chart-bar">
                            <div class="chart-label">{{ $category['name'] }}</div>
                            <div class="chart-bar-wrapper">
                                <div class="chart-bar-fill" style="width: {{ ($category['count'] / $maxProductCount) * 100 }}%;"></div>
                            </div>
                            <div class="chart-value">{{ $category['count'] }}</div>
                        </div>
                    @endforeach
                @else
                    <div class="no-data">Tidak ada data produk</div>
                @endif
            </div>

            <!-- Merchants by Segmentation -->
            <div class="chart-item">
                <div class="chart-title">Merchant per Segmentasi</div>
                @php
                    $maxMerchantCount = collect($data['merchants']['by_segmentation'])->max('count') ?: 1;
                @endphp
                @if(count($data['merchants']['by_segmentation']) > 0)
                    @foreach($data['merchants']['by_segmentation'] as $segment)
                        <div class="chart-bar">
                            <div class="chart-label">{{ $segment['name'] }}</div>
                            <div class="chart-bar-wrapper">
                                <div class="chart-bar-fill" style="width: {{ ($segment['count'] / $maxMerchantCount) * 100 }}%;"></div>
                            </div>
                            <div class="chart-value">{{ $segment['count'] }}</div>
                        </div>
                    @endforeach
                @else
                    <div class="no-data">Tidak ada data merchant</div>
                @endif
            </div>
        </div>
    </div>

    <!-- Recent Orders -->
    <div class="section">
        <div class="section-title">10 Order Terbaru</div>
        @if(count($data['recent_orders']) > 0)
            <table>
                <thead>
                    <tr>
                        <th style="width: 4%;">No</th>
                        <th style="width: 6%;">ID</th>
                        <th style="width: 18%;">Nama</th>
                        <th style="width: 12%;">Telepon</th>
                        <th style="width: 10%;">Tanggal</th>
                        <th style="width: 10%;">Waktu</th>
                        <th style="width: 25%;">Jasa</th>
                        <th style="width: 15%; text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['recent_orders'] as $index => $order)
                        <tr>
                            <td class="text-center">{{ $index + 1 }}</td>
                            <td class="text-center">{{ $order['id'] }}</td>
                            <td>{{ $order['nama'] }}</td>
                            <td>{{ $order['tel'] }}</td>
                            <td class="text-center">{{ $order['tanggal'] }}</td>
                            <td class="text-center">{{ $order['waktu'] }}</td>
                            <td>{{ $order['jasa']['title'] ?? '-' }}</td>
                            <td class="text-right">Rp {{ number_format($order['total'], 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="no-data">Tidak ada data order</div>
        @endif
    </div>

    <!-- Recent Reports -->
    <div class="section">
        <div class="section-title">10 Report Terbaru</div>
        @if(count($data['recent_reports']) > 0)
            <table>
                <thead>
                    <tr>
                        <th style="width: 4%;">No</th>
                        <th style="width: 6%;">ID</th>
                        <th style="width: 15%;">Reporter</th>
                        <th style="width: 12%;">Tipe</th>
                        <th style="width: 15%;">Alasan</th>
                        <th style="width: 25%;">Komentar</th>
                        <th style="width: 10%;">Status</th>
                        <th style="width: 13%;">Tanggal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['recent_reports'] as $index => $report)
                        <tr>
                            <td class="text-center">{{ $index + 1 }}</td>
                            <td class="text-center">{{ $report['id'] }}</td>
                            <td>{{ $report['reporter']['name'] ?? '-' }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $report['reportable_type'] ?? '-')) }}</td>
                            <td>{{ $report['reason']['reason_title'] ?? '-' }}</td>
                            <td>{{ Str::limit($report['report_comment'] ?? '-', 40) }}</td>
                            <td>
                                @php
                                    $badgeClass = match($report['status']) {
                                        'pending' => 'badge-warning',
                                        'resolved' => 'badge-success',
                                        'rejected' => 'badge-danger',
                                        default => 'badge-info'
                                    };
                                @endphp
                                <span class="badge {{ $badgeClass }}">{{ ucfirst($report['status']) }}</span>
                            </td>
                            <td class="text-center">{{ \Carbon\Carbon::parse($report['created_at'])->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="no-data">Tidak ada data report</div>
        @endif
    </div>

    <!-- Footer -->
    <div class="footer">
        <p><strong>© {{ date('Y') }} SUMILIR - Marketplace UMKM Banyuanyar</strong></p>
        <p>Laporan ini dibuat secara otomatis oleh sistem pada {{ $metadata['generated_at'] }}</p>
    </div>
</body>
</html>