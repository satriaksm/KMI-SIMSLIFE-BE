<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Detail Merchant - {{ $merchant->name }}</title>
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

        .badge-approved {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-published {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-draft {
            background: #e5e7eb;
            color: #4b5563;
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

        .address-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px dotted #ddd;
        }

        .address-title {
            font-size: 12px;
            font-weight: bold;
            color: #058895;
            margin-bottom: 8px;
        }

        .chart-placeholder {
            background: #f0f9fa;
            border: 2px dashed #b8e6ea;
            border-radius: 4px;
            padding: 20px;
            text-align: center;
            color: #666;
            font-style: italic;
            margin: 10px 0;
        }

        .info-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 8px 10px;
            margin: 10px 0;
            font-size: 9px;
            color: #856404;
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
        <h1>Detail Merchant</h1>
        <p>Informasi Lengkap Merchant - Platform Marketplace SUMILIR</p>
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

    <!-- Profile with Address -->
    <div class="section">
        <div class="section-title">Profil Merchant</div>
        <div class="profile-card">
            <div class="profile-grid">
                <div class="profile-item">
                    <div class="profile-label">Nama Merchant:</div>
                    <div class="profile-value"><strong>{{ $merchant->name }}</strong></div>
                </div>
                <div class="profile-item">
                    <div class="profile-label">Slug:</div>
                    <div class="profile-value">{{ $merchant->slug }}</div>
                </div>
                <div class="profile-item">
                    <div class="profile-label">Owner:</div>
                    <div class="profile-value">{{ $merchant->user->name ?? '-' }} ({{ $merchant->user->email ?? '-' }})</div>
                </div>
                <div class="profile-item">
                    <div class="profile-label">Telepon:</div>
                    <div class="profile-value">{{ $merchant->phone ?? '-' }}</div>
                </div>
                <div class="profile-item">
                    <div class="profile-label">Segmentasi:</div>
                    <div class="profile-value">{{ $merchant->segmentation->name ?? '-' }}</div>
                </div>
                <div class="profile-item">
                    <div class="profile-label">Status:</div>
                    <div class="profile-value">
                        @php
                            $badgeClass = match($merchant->status) {
                                'approved' => 'badge-approved',
                                'pending' => 'badge-pending',
                                'rejected' => 'badge-rejected',
                                default => 'badge-pending'
                            };
                        @endphp
                        <span class="badge {{ $badgeClass }}">{{ ucfirst($merchant->status) }}</span>
                    </div>
                </div>
                @if($merchant->description)
                <div class="profile-item">
                    <div class="profile-label">Deskripsi:</div>
                    <div class="profile-value">{{ $merchant->description }}</div>
                </div>
                @endif
                <div class="profile-item">
                    <div class="profile-label">Terdaftar:</div>
                    <div class="profile-value">{{ $merchant->created_at->format('d F Y, H:i') }}</div>
                </div>
                @if($merchant->response_at)
                <div class="profile-item">
                    <div class="profile-label">Direview:</div>
                    <div class="profile-value">{{ \Carbon\Carbon::parse($merchant->response_at)->format('d F Y, H:i') }}</div>
                </div>
                @endif
            </div>

            <!-- Address Section (Merged) -->
            @if($merchant->addresses && $merchant->addresses->count() > 0)
            <div class="address-section">
                <div class="address-title">Alamat Merchant</div>
                @foreach($merchant->addresses as $address)
                <div style="margin-bottom: 10px; padding: 8px; background: white; border: 1px solid #ddd; border-radius: 3px;">
                    <div class="profile-grid">
                        <div class="profile-item">
                            <div class="profile-label">Label:</div>
                            <div class="profile-value"><strong>{{ ucfirst($address->label ?? 'Alamat') }}</strong></div>
                        </div>
                        <div class="profile-item">
                            <div class="profile-label">Detail:</div>
                            <div class="profile-value">{{ $address->detail ?? '-' }}</div>
                        </div>
                        <div class="profile-item">
                            <div class="profile-label">Kelurahan:</div>
                            <div class="profile-value">{{ $address->village->name ?? '-' }}</div>
                        </div>
                        <div class="profile-item">
                            <div class="profile-label">Kecamatan:</div>
                            <div class="profile-value">{{ $address->district->name ?? '-' }}</div>
                        </div>
                        <div class="profile-item">
                            <div class="profile-label">Kota:</div>
                            <div class="profile-value">{{ $address->city->name ?? '-' }}</div>
                        </div>
                        <div class="profile-item">
                            <div class="profile-label">Provinsi:</div>
                            <div class="profile-value">{{ $address->province->name ?? '-' }}</div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    <!-- Statistics -->
    <div class="section">
        <div class="section-title">Statistik</div>
        <div class="stats-grid">
            <div class="stats-item">
                <span class="stats-number">{{ $merchant->products_count ?? 0 }}</span>
                <span class="stats-label">Total Products</span>
            </div>
            <div class="stats-item">
                <span class="stats-number">{{ $merchant->vouchers_count ?? 0 }}</span>
                <span class="stats-label">Total Vouchers</span>
            </div>
            <div class="stats-item">
                <span class="stats-number">{{ $merchant->events->count() ?? 0 }}</span>
                <span class="stats-label">Total Events</span>
            </div>
        </div>
    </div>

    <!-- Charts Info -->
    <div class="section">
        <div class="section-title">Data Transaksi & Produk</div>
        <div class="info-box">
            <strong>📊 Informasi Chart:</strong> Data grafik transaksi 30 hari terakhir dan produk terorder per kategori tersedia di dashboard online untuk visualisasi yang lebih interaktif.
        </div>
        <div class="chart-placeholder">
            Chart: Total Transaksi 30 Hari Terakhir
            <br><small>(Visualisasi tersedia di dashboard online)</small>
        </div>
        <div class="chart-placeholder">
            Chart: Produk Terorder per Kategori (30 Hari)
            <br><small>(Visualisasi tersedia di dashboard online)</small>
        </div>
    </div>

    <!-- Products -->
    @if($merchant->products && $merchant->products->count() > 0)
    <div class="section">
        <div class="section-title">Daftar Products ({{ $merchant->products->count() }})</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 30%;">Nama Product</th>
                    <th style="width: 15%;">SKU</th>
                    <th style="width: 15%;">Kategori</th>
                    <th style="width: 13%;">Harga</th>
                    <th style="width: 8%;">Stok</th>
                    <th style="width: 14%;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($merchant->products as $index => $product)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>{{ $product->name }}</td>
                        <td>{{ $product->sku ?? '-' }}</td>
                        <td>
                            @if($product->categories && $product->categories->count() > 0)
                                {{ $product->categories->first()->name }}
                            @else
                                -
                            @endif
                        </td>
                        <td>Rp {{ number_format($product->price ?? 0, 0, ',', '.') }}</td>
                        <td class="text-center">{{ $product->stock ?? 0 }}</td>
                        <td>
                            @php
                                $statusBadgeClass = $product->status === 'published' ? 'badge-published' : 'badge-draft';
                            @endphp
                            <span class="badge {{ $statusBadgeClass }}">{{ ucfirst($product->status) }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="section">
        <div class="section-title">Daftar Products</div>
        <div class="no-data">Merchant ini belum memiliki produk</div>
    </div>
    @endif

    <!-- Vouchers Detail -->
    @if($merchant->vouchers && $merchant->vouchers->count() > 0)
    <div class="section">
        <div class="section-title">Daftar Vouchers ({{ $merchant->vouchers->count() }})</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 15%;">Kode Voucher</th>
                    <th style="width: 25%;">Deskripsi</th>
                    <th style="width: 10%;">Tipe</th>
                    <th style="width: 12%;">Nilai</th>
                    <th style="width: 10%;">Min. Belanja</th>
                    <th style="width: 10%;">Digunakan</th>
                    <th style="width: 13%;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($merchant->vouchers as $index => $voucher)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td><strong>{{ $voucher->voucher_code }}</strong></td>
                        <td>{{ Str::limit($voucher->voucher_description ?? '-', 40) }}</td>
                        <td>{{ $voucher->voucher_type === 'percent' ? 'Persentase' : 'Nominal' }}</td>
                        <td>
                            @if($voucher->voucher_type === 'percent')
                                {{ $voucher->value }}%
                            @else
                                Rp {{ number_format($voucher->value, 0, ',', '.') }}
                            @endif
                        </td>
                        <td>Rp {{ number_format($voucher->min_purchase_amount ?? 0, 0, ',', '.') }}</td>
                        <td class="text-center">{{ $voucher->usages_count ?? 0 }} / {{ $voucher->usage_limit ?? '∞' }}</td>
                        <td>
                            @php
                                $statusBadgeClass = match($voucher->voucher_status) {
                                    'active' => 'badge-approved',
                                    'expired' => 'badge-rejected',
                                    'inactive' => 'badge-draft',
                                    default => 'badge-pending'
                                };
                            @endphp
                            <span class="badge {{ $statusBadgeClass }}">{{ ucfirst($voucher->voucher_status) }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="section">
        <div class="section-title">Vouchers</div>
        <div class="no-data">Merchant ini belum memiliki voucher</div>
    </div>
    @endif

    <!-- Events Detail -->
    @if($merchant->events && $merchant->events->count() > 0)
    <div class="section">
        <div class="section-title">Daftar Events ({{ $merchant->events->count() }})</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 30%;">Nama Event</th>
                    <th style="width: 30%;">Deskripsi</th>
                    <th style="width: 20%;">Periode</th>
                    <th style="width: 15%;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($merchant->events as $index => $event)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td><strong>{{ $event->event_name }}</strong></td>
                        <td>{{ Str::limit($event->event_description ?? '-', 50) }}</td>
                        <td>
                            {{ \Carbon\Carbon::parse($event->event_start_date)->format('d M Y') }} -
                            {{ \Carbon\Carbon::parse($event->event_end_date)->format('d M Y') }}
                        </td>
                        <td>
                            @php
                                $statusBadgeClass = match($event->status) {
                                    'active' => 'badge-approved',
                                    'ended' => 'badge-rejected',
                                    'upcoming' => 'badge-pending',
                                    default => 'badge-draft'
                                };
                            @endphp
                            <span class="badge {{ $statusBadgeClass }}">{{ ucfirst($event->status) }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div class="section">
        <div class="section-title">Events</div>
        <div class="no-data">Merchant ini belum berpartisipasi dalam event apapun</div>
    </div>
    @endif

    <!-- Timeline -->
    <div class="section">
        <div class="section-title">Timeline</div>
        <div class="profile-card">
            <div class="profile-grid">
                <div class="profile-item">
                    <div class="profile-label">Merchant Terdaftar:</div>
                    <div class="profile-value">{{ $merchant->created_at->format('d F Y, H:i') }}</div>
                </div>
                <div class="profile-item">
                    <div class="profile-label">Terakhir Diupdate:</div>
                    <div class="profile-value">{{ $merchant->updated_at->format('d F Y, H:i') }}</div>
                </div>
                @if($merchant->response_at)
                <div class="profile-item">
                    <div class="profile-label">Direview Pada:</div>
                    <div class="profile-value">{{ \Carbon\Carbon::parse($merchant->response_at)->format('d F Y, H:i') }}</div>
                </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p><strong>© {{ date('Y') }} SUMILIR - Marketplace UMKM Banyuanyar</strong></p>
        <p>Laporan ini dibuat secara otomatis oleh sistem pada {{ $metadata['generated_at'] }}</p>
    </div>
</body>
</html>