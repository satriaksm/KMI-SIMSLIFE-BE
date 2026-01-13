<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Detail User - {{ $user->name }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            margin: 0;
            padding: 15px;
            padding-bottom: 50px;
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

        .profile-card {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .profile-grid {
            display: table;
            width: 100%;
        }

        .profile-item {
            display: table-row;
        }

        .profile-label {
            display: table-cell;
            padding: 5px 10px 5px 0;
            font-weight: bold;
            color: #555;
            width: 150px;
        }

        .profile-value {
            display: table-cell;
            padding: 5px 0;
            color: #333;
        }

        .stats-grid {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }

        .stats-item {
            display: table-cell;
            width: 33.33%;
            text-align: center;
            padding: 10px;
            background: #f0f9fa;
            border: 1px solid #b8e6ea;
        }

        .stats-number {
            font-size: 18px;
            font-weight: bold;
            color: #058895;
            display: block;
            margin-bottom: 3px;
        }

        .stats-label {
            font-size: 9px;
            color: #666;
            text-transform: uppercase;
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
            margin-right: 3px;
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

        .chart-container {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #ddd;
            margin-top: 10px;
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
            width: 80px;
            font-size: 9px;
            color: #666;
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
            background: #44a4b4;
            border-radius: 3px;
            min-width: 2px;
        }

        .chart-value {
            font-size: 9px;
            font-weight: bold;
            color: #44a4b4;
            min-width: 25px;
            text-align: right;
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
            padding: 20px;
            font-style: italic;
            font-size: 10px;
        }

        .text-center {
            text-align: center;
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
        <h1>Detail User</h1>
        <p>Informasi Lengkap User - Platform Marketplace SUMILIR</p>
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
    </div>

    <!-- Profile -->
    <div class="section">
        <div class="section-title">Profil User</div>
        <div class="profile-card">
            <div class="profile-grid">
                <div class="profile-item">
                    <div class="profile-label">Nama:</div>
                    <div class="profile-value"><strong>{{ $user->name }}</strong></div>
                </div>
                <div class="profile-item">
                    <div class="profile-label">Email:</div>
                    <div class="profile-value">{{ $user->email }}</div>
                </div>
                <div class="profile-item">
                    <div class="profile-label">Telepon:</div>
                    <div class="profile-value">{{ $user->phone ?? '-' }}</div>
                </div>
                <div class="profile-item">
                    <div class="profile-label">NIK:</div>
                    <div class="profile-value">{{ $user->nik ?? '-' }}</div>
                </div>
                <div class="profile-item">
                    <div class="profile-label">Status:</div>
                    <div class="profile-value">
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
                    </div>
                </div>
                <div class="profile-item">
                    <div class="profile-label">Roles:</div>
                    <div class="profile-value">
                        @foreach($user->roles as $role)
                            <span class="badge badge-role">{{ ucfirst($role->name) }}</span>
                        @endforeach
                    </div>
                </div>
                <div class="profile-item">
                    <div class="profile-label">Email Verified:</div>
                    <div class="profile-value">{{ $user->email_verified_at ? 'Ya' : 'Tidak' }}</div>
                </div>
                <div class="profile-item">
                    <div class="profile-label">Terdaftar:</div>
                    <div class="profile-value">{{ $user->created_at->format('d F Y, H:i') }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="section">
        <div class="section-title">Statistik Aktivitas</div>
        <div class="stats-grid">
            <div class="stats-item">
                <span class="stats-number">{{ $user->merchants_count ?? 0 }}</span>
                <span class="stats-label">Merchants</span>
            </div>
            <div class="stats-item">
                <span class="stats-number">{{ $user->community_posts_count ?? 0 }}</span>
                <span class="stats-label">Community Posts</span>
            </div>
            <div class="stats-item">
                <span class="stats-number">{{ $user->post_comments_count ?? 0 }}</span>
                <span class="stats-label">Post Comments</span>
            </div>
        </div>
    </div>

    <!-- Login Trend -->
    @if(count($loginTrend) > 0)
    <div class="section">
        <div class="section-title">Trend Login (30 Hari Terakhir)</div>
        <div class="chart-container">
            <div class="chart-title">Total Login per Hari</div>
            @php
                $maxLogin = collect($loginTrend)->max('total') ?: 1;
                // Show only last 15 days untuk menghemat space
                $displayTrend = array_slice($loginTrend, -15);
            @endphp
            @foreach($displayTrend as $day)
                <div class="chart-bar">
                    <div class="chart-label">{{ \Carbon\Carbon::parse($day['date'])->format('d M') }}</div>
                    <div class="chart-bar-wrapper">
                        <div class="chart-bar-fill" style="width: {{ ($day['total'] / $maxLogin) * 100 }}%;"></div>
                    </div>
                    <div class="chart-value">{{ $day['total'] }}</div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Merchants -->
    @if($user->merchants && $user->merchants->count() > 0)
    <div class="section">
        <div class="section-title">Daftar Merchants ({{ $user->merchants->count() }})</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 25%;">Nama Merchant</th>
                    <th style="width: 15%;">Segmentasi</th>
                    <th style="width: 10%;">Produk</th>
                    <th style="width: 10%;">Voucher</th>
                    <th style="width: 15%;">Status</th>
                    <th style="width: 20%;">Terdaftar</th>
                </tr>
            </thead>
            <tbody>
                @foreach($user->merchants as $index => $merchant)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>{{ $merchant->name }}</td>
                        <td>{{ $merchant->segmentation->name ?? '-' }}</td>
                        <td class="text-center">{{ $merchant->products->count() }}</td>
                        <td class="text-center">{{ $merchant->vouchers_count ?? 0 }}</td>
                        <td>
                            @php
                                $statusBadgeClass = match($merchant->status) {
                                    'approved' => 'badge-active',
                                    'pending' => 'badge-watchlist',
                                    'rejected' => 'badge-suspended',
                                    default => 'badge-inactive'
                                };
                            @endphp
                            <span class="badge {{ $statusBadgeClass }}">{{ ucfirst($merchant->status) }}</span>
                        </td>
                        <td>{{ $merchant->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <p><strong>© {{ date('Y') }} SUMILIR - Marketplace UMKM Banyuanyar</strong></p>
        <p>Laporan ini dibuat secara otomatis oleh sistem pada {{ $metadata['generated_at'] }}</p>
    </div>
</body>
</html>