<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $metadata['title'] }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #333; line-height: 1.5; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #f3f4f6; padding-bottom: 20px; }
        .logo { max-height: 60px; margin-bottom: 10px; }
        .title { font-size: 20px; font-weight: bold; color: #111; margin: 0; }
        .metadata { font-size: 10px; color: #666; margin-top: 5px; }
        
        .section { margin-bottom: 25px; }
        .section-title { font-size: 14px; font-weight: bold; color: #000; border-left: 4px solid #f97316; padding-left: 10px; margin-bottom: 15px; background: #fff7ed; padding-top: 5px; padding-bottom: 5px; }
        
        .grid { display: flex; flex-wrap: wrap; margin-right: -10px; margin-left: -10px; }
        .col { flex: 0 0 50%; padding: 0 10px; }
        
        .info-label { font-weight: bold; color: #666; font-size: 10px; text-transform: uppercase; margin-bottom: 2px; }
        .info-value { font-size: 12px; color: #111; margin-bottom: 10px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background-color: #f9fafb; color: #4b5563; font-weight: bold; text-align: left; padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 11px; }
        td { padding: 10px; border-bottom: 1px solid #f3f4f6; vertical-align: top; font-size: 11px; }
        
        .badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .badge-published { background-color: #dcfce7; color: #166534; }
        .badge-draft { background-color: #f3f4f6; color: #374151; }
        .badge-archived { background-color: #fee2e2; color: #991b1b; }
        
        .footer { position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 10px; color: #999; border-top: 1px solid #eee; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        @if($logoBase64)
            <img src="{{ $logoBase64 }}" class="logo" alt="Logo">
        @endif
        <h1 class="title">{{ $metadata['title'] }}</h1>
        <div class="metadata">
            Dicetak pada: {{ $metadata['generated_at'] }} | Oleh: {{ $metadata['generated_by'] }}
        </div>
    </div>

    <div class="section">
        <div class="section-title">Informasi Umum</div>
        <table style="border: none;">
            <tr>
                <td style="width: 50%; border: none;">
                    <div class="info-label">Nama Event</div>
                    <div class="info-value">{{ $event->event_name }}</div>
                    
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <span class="badge badge-{{ $event->status }}">
                            {{ strtoupper($event->status) }}
                        </span>
                    </div>
                </td>
                <td style="width: 50%; border: none;">
                    <div class="info-label">Periode</div>
                    <div class="info-value">
                        {{ $event->event_start_date->format('d F Y') }} - {{ $event->event_end_date->format('d F Y') }}
                    </div>
                    
                    <div class="info-label">Total Partisipan</div>
                    <div class="info-value">{{ $event->active_merchants_count }} Merchant | {{ $event->vouchers_count }} Voucher</div>
                </td>
            </tr>
        </table>
        
        <div class="info-label" style="margin-top: 10px;">Deskripsi</div>
        <div class="info-value" style="background: #f9fafb; padding: 10px; border-radius: 8px; font-size: 11px;">
            {{ $event->event_description ?: 'Tidak ada deskripsi.' }}
        </div>
    </div>

    <div class="section">
        <div class="section-title">Daftar UMKM Terdaftar ({{ $event->active_merchants_count }})</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 30%;">Nama Merchant</th>
                    <th style="width: 25%;">Segmentasi</th>
                    <th style="width: 25%;">Paguyuban</th>
                    <th style="width: 15%;">Tgl Bergabung</th>
                </tr>
            </thead>
            <tbody>
                @forelse($event->merchants as $index => $merchant)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td><strong>{{ $merchant->name }}</strong></td>
                        <td>{{ $merchant->segmentation->name ?? '-' }}</td>
                        <td>{{ $merchant->paguyuban->name ?? '-' }}</td>
                        <td>{{ $merchant->pivot->responded_at ? date('d/m/Y', strtotime($merchant->pivot->responded_at)) : '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="text-align: center; color: #999;">Belum ada merchant yang bergabung.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Voucher Event ({{ $event->vouchers_count }})</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 15%;">Kode</th>
                    <th style="width: 30%;">Nama Voucher</th>
                    <th style="width: 15%;">Potongan</th>
                    <th style="width: 15%;">Min. Belanja</th>
                    <th style="width: 20%;">Masa Berlaku</th>
                </tr>
            </thead>
            <tbody>
                @forelse($event->vouchers as $index => $voucher)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td style="font-family: monospace; font-weight: bold;">{{ $voucher->voucher_code }}</td>
                        <td>{{ $voucher->voucher_name }}</td>
                        <td>
                            {{ $voucher->voucher_type === 'percent' ? $voucher->value . '%' : 'Rp ' . number_format($voucher->value, 0, ',', '.') }}
                        </td>
                        <td>Rp {{ number_format($voucher->min_purchase_amount, 0, ',', '.') }}</td>
                        <td style="font-size: 10px;">
                            {{ $voucher->voucher_start_date->format('d/m/y') }} - {{ $voucher->voucher_end_date->format('d/m/y') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; color: #999;">Tidak ada voucher yang tertaut.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(isset($analytics) && !empty($analytics))
    <div style="page-break-before: always;"></div>
    <div class="header" style="margin-bottom: 20px;">
        <h2 style="margin: 0; color: #194a7a;">Statistik & Analitik Event</h2>
        <div style="font-size: 12px; color: #666; margin-top: 5px;">Data teragregasi hingga {{ date('d/m/Y H:i') }}</div>
    </div>

    <div class="section">
        <div class="section-title">Ringkasan Transaksi</div>
        <table style="width: 100%;">
            <tr>
                <td style="width: 50%; border: none; padding: 0; vertical-align: top;">
                    <table style="margin-bottom: 0;">
                        <tr><td style="width: 60%; background: #f9fafb;">Total Transaksi</td><td>{{ number_format($analytics['summary']['total_transactions'] ?? 0, 0, ',', '.') }}</td></tr>
                        <tr><td style="background: #f9fafb;">Pesanan Selesai</td><td>{{ number_format($analytics['summary']['completed_orders'] ?? 0, 0, ',', '.') }}</td></tr>
                        <tr><td style="background: #f9fafb;">Pesanan Batal</td><td>{{ number_format($analytics['summary']['cancelled_orders'] ?? 0, 0, ',', '.') }}</td></tr>
                        <tr><td style="background: #f9fafb; font-weight: bold;">Total Pendapatan</td><td style="font-weight: bold; color: #16a34a;">Rp {{ number_format($analytics['summary']['total_revenue'] ?? 0, 0, ',', '.') }}</td></tr>
                    </table>
                </td>
                <td style="width: 50%; border: none; padding: 0; padding-left: 10px; vertical-align: top;">
                    <table style="margin-bottom: 0;">
                        <tr><td style="width: 60%; background: #f9fafb;">Pembeli Unik</td><td>{{ number_format($analytics['summary']['unique_buyers'] ?? 0, 0, ',', '.') }} orang</td></tr>
                        <tr><td style="background: #f9fafb;">Rata-rata Transaksi</td><td>Rp {{ number_format($analytics['summary']['avg_transaction'] ?? 0, 0, ',', '.') }}</td></tr>
                        <tr><td style="background: #f9fafb;">Rating Rata-rata</td><td>{{ $analytics['rating']['avg_rating'] ?? '-' }} / 5 ({{ $analytics['rating']['total_reviews'] ?? 0 }} ulasan)</td></tr>
                        <tr><td style="background: #f9fafb;">Penggunaan Voucher</td><td>{{ number_format($analytics['summary']['voucher_usage_count'] ?? 0, 0, ',', '.') }}x (Rp {{ number_format($analytics['summary']['total_discount'] ?? 0, 0, ',', '.') }})</td></tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Ranking UMKM</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 45%;">Nama UMKM</th>
                    <th style="width: 20%; text-align: center;">Total Transaksi</th>
                    <th style="width: 30%; text-align: right;">Total Pendapatan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($analytics['top_merchants_revenue'] ?? [] as $index => $m)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $m['name'] }} <br><span style="font-size: 10px; color: #666;">{{ $m['segmentation'] }}</span></td>
                        <td style="text-align: center;">{{ number_format($m['total_orders'], 0, ',', '.') }}</td>
                        <td style="text-align: right;">Rp {{ number_format($m['total_revenue'], 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align: center;">Belum ada data transaksi UMKM.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="section-title">Kategori & Produk Terlaris</div>
        <table style="width: 100%;">
            <tr>
                <td style="width: 50%; border: none; padding: 0; vertical-align: top;">
                    <table style="margin-bottom: 0;">
                        <thead>
                            <tr><th>Kategori</th><th style="text-align: center;">Unit Terjual</th></tr>
                        </thead>
                        <tbody>
                            @forelse($analytics['top_categories'] ?? [] as $c)
                                <tr>
                                    <td>{{ $c['category_name'] }}</td>
                                    <td style="text-align: center;">{{ number_format($c['total_qty'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" style="text-align: center;">Belum ada data kategori.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </td>
                <td style="width: 50%; border: none; padding: 0; padding-left: 10px; vertical-align: top;">
                    <table style="margin-bottom: 0;">
                        <thead>
                            <tr><th>Produk</th><th style="text-align: center;">Unit Terjual</th></tr>
                        </thead>
                        <tbody>
                            @forelse($analytics['top_products'] ?? [] as $p)
                                <tr>
                                    <td style="font-size: 11px;">{{ $p['product_name'] }}<br><span style="color: #666; font-size: 9px;">{{ $p['merchant_name'] }}</span></td>
                                    <td style="text-align: center;">{{ number_format($p['total_qty'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" style="text-align: center;">Belum ada data produk.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </td>
            </tr>
        </table>
    </div>
    @endif

    <div class="footer">
        Halaman 1 dari 1 | SUMILIR - Digital Ecosystem for UMKM | &copy; {{ date('Y') }}
    </div>
</body>
</html>
