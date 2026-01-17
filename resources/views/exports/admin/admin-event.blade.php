<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Data Events</title>
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
        
        .event-desc {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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

        .badge-published {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-draft {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-archived {
            background: #fee2e2;
            color: #991b1b;
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

        .event-desc {
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            font-size: 8px;
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
        <h1>Laporan Data Events</h1>
        <p>Daftar Events - Platform Marketplace SUMILIR</p>
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
        @if($metadata['filters']['search'] !== '-')
        <div class="metadata-row">
            <div class="metadata-label">Pencarian:</div>
            <div class="metadata-value">{{ $metadata['filters']['search'] }}</div>
        </div>
        @endif
    </div>

    <!-- Summary Statistics -->
    <div class="summary-section">
        <div class="summary-grid">
            <div class="summary-item">
                <span class="summary-number">{{ $events->count() }}</span>
                <span class="summary-label">Total Events</span>
            </div>
            <div class="summary-item">
                <span class="summary-number">{{ $events->where('status', 'published')->count() }}</span>
                <span class="summary-label">Published</span>
            </div>
            <div class="summary-item">
                <span class="summary-number">{{ $events->where('status', 'draft')->count() }}</span>
                <span class="summary-label">Draft</span>
            </div>
            <div class="summary-item">
                <span class="summary-number">{{ $events->sum('merchants_count') }}</span>
                <span class="summary-label">Total Merchants</span>
            </div>
        </div>
    </div>

    <!-- Events Table -->
    @if(count($events) > 0)
        <table>
            <thead>
                <tr>
                    <th style="width: 3%;">No</th>
                    <th style="width: 18%;">Nama Event</th>
                    <th style="width: 25%;">Deskripsi</th>
                    <th style="width: 12%;">Tanggal Mulai</th>
                    <th style="width: 12%;">Tanggal Selesai</th>
                    <th style="width: 8%;">Pembuat</th>
                    <th style="width: 7%;">Merchants</th>
                    <th style="width: 7%;">Vouchers</th>
                    <th style="width: 8%;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($events as $index => $event)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>{{ $event->event_name }}</td>
                        <td>
                            <div class="event-desc">
                                {{ $event->event_description ?: '-' }}
                            </div>
                        </td>
                        <td class="text-center">
                            {{ \Carbon\Carbon::parse($event->event_start_date)->format('d/m/Y') }}
                        </td>
                        <td class="text-center">
                            {{ \Carbon\Carbon::parse($event->event_end_date)->format('d/m/Y') }}
                        </td>
                        <td>{{ $event->creator->name ?? '-' }}</td>
                        <td class="text-center">{{ $event->merchants_count ?? 0 }}</td>
                        <td class="text-center">{{ $event->vouchers_count ?? 0 }}</td>
                        <td>
                            @php
                                $badgeClass = match($event->status) {
                                    'published' => 'badge-published',
                                    'draft' => 'badge-draft',
                                    'archived' => 'badge-archived',
                                    default => 'badge-draft'
                                };
                            @endphp
                            <span class="badge {{ $badgeClass }}">{{ ucfirst($event->status) }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="no-data">Tidak ada data event yang ditemukan</div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <p><strong>© {{ date('Y') }} SUMILIR - Marketplace UMKM Banyuanyar</strong></p>
        <p>Laporan ini dibuat secara otomatis oleh sistem pada {{ $metadata['generated_at'] }}</p>
    </div>
</body>
</html>