<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Data Customer</title>
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

        .badge-suspended {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-watchlist {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-inactive {
            background: #e5e7eb;
            color: #4b5563;
        }

        .badge-role {
            background: #dbeafe;
            color: #1e40af;
            margin-right: 2px;
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
        <h1>Laporan Data Customer</h1>
        <p>Daftar Customer - Platform Marketplace SUMILIR</p>
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
            <div class="metadata-label">Filter Role:</div>
            <div class="metadata-value">{{ $metadata['filters']['role'] }}</div>
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
        <h3>Total Customer: {{ $metadata['total_users'] }}</h3>
    </div>

    <!-- Users Table -->
    @if(count($users) > 0)
        <table>
            <thead>
                <tr>
                    <th style="width: 3%;">No</th>
                    <th style="width: 15%;">Nama</th>
                    <th style="width: 17%;">Email</th>
                    <th style="width: 10%;">Telepon</th>
                    <th style="width: 12%;">NIK</th>
                    <th style="width: 12%;">Roles</th>
                    <th style="width: 18%;">Merchants</th>
                    <th style="width: 8%;">Status</th>
                    <th style="width: 10%;">Terdaftar</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $index => $user)
                    <tr>
                        <td style="text-align: center;">{{ $index + 1 }}</td>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->phone ?? '-' }}</td>
                        <td>{{ $user->nik ?? '-' }}</td>
                        <td>
                            @foreach($user->roles as $role)
                                <span class="badge badge-role">{{ ucfirst($role->name) }}</span>
                            @endforeach
                        </td>
                        <td>
                            @if($user->merchants && $user->merchants->count() > 0)
                                @foreach($user->merchants as $merchant)
                                    <span style="font-size: 8px;">{{ $merchant->name }}</span>
                                    @if(!$loop->last), @endif
                                @endforeach
                            @else
                                -
                            @endif
                        </td>
                        <td>
                            @php
                                $badgeClass = match($user->status ?? 'active') {
                                    'active' => 'badge-active',
                                    'suspended' => 'badge-suspended',
                                    'watchlist' => 'badge-watchlist',
                                    'inactive' => 'badge-inactive',
                                    default => 'badge-active'
                                };
                            @endphp
                            <span class="badge {{ $badgeClass }}">{{ ucfirst($user->status ?? 'active') }}</span>
                        </td>
                        <td>{{ $user->created_at->format('d/m/Y') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div class="no-data">Tidak ada data customer yang ditemukan</div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <p><strong>© {{ date('Y') }} SUMILIR - Marketplace UMKM Banyuanyar</strong></p>
        <p>Laporan ini dibuat secara otomatis oleh sistem pada {{ $metadata['generated_at'] }}</p>
    </div>
</body>
</html>