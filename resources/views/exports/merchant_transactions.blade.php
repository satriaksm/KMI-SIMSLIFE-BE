<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan Transaksi - {{ $merchant->name }}</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #333; }
    .header { margin-bottom: 20px; border-bottom: 2px solid #2563eb; padding-bottom: 10px; }
    .title { font-size: 18px; font-weight: bold; color: #1e3a8a; margin: 0; }
    .subtitle { font-size: 12px; color: #6b7280; margin-top: 4px; }
    .summary-box { margin-bottom: 15px; padding: 10px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; }
    .summary-table { width: 100%; border: none; }
    .summary-table td { border: none; padding: 4px; font-size: 11px; }
    table.data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    table.data-table th, table.data-table td { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: middle; }
    table.data-table th { background-color: #f1f5f9; text-align: left; font-weight: bold; color: #1e293b; }
    .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
    .badge-completed { background-color: #dcfce7; color: #166534; }
    .badge-pending { background-color: #fef9c3; color: #854d0e; }
    .badge-cancelled { background-color: #fee2e2; color: #991b1b; }
    .badge-processing { background-color: #e0e7ff; color: #3730a3; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
  </style>
</head>
<body>
  <div class="header">
    <table style="width: 100%; border: none;">
      <tr>
        <td style="border: none; padding: 0;">
          <h1 class="title">Laporan Riwayat Transaksi</h1>
          <div class="subtitle">{{ $merchant->name }} &bull; Diunduh pada: {{ now()->format('d/m/Y H:i') }}</div>
        </td>
      </tr>
    </table>
  </div>

  <div class="summary-box">
    <table class="summary-table">
      <tr>
        <td style="width: 25%;"><strong>Total Transaksi:</strong> {{ $totalTransactions }} Pesanan</td>
        <td style="width: 35%;"><strong>Pendapatan Bersih (Selesai):</strong> Rp {{ number_format($totalRevenue, 0, ',', '.') }}</td>
        <td style="width: 40%;"><strong>Periode:</strong> {{ $startDate ?: 'Semua' }} s/d {{ $endDate ?: 'Sekarang' }}</td>
      </tr>
    </table>
  </div>

  <table class="data-table">
    <thead>
      <tr>
        <th style="width: 5%;" class="text-center">No</th>
        <th style="width: 15%;">ID Pesanan</th>
        <th style="width: 14%;">Waktu</th>
        <th style="width: 18%;">Pelanggan</th>
        <th style="width: 12%;">Pengiriman</th>
        <th style="width: 10%;">Pembayaran</th>
        <th style="width: 12%;">Status</th>
        <th style="width: 14%;" class="text-right">Total (Rp)</th>
      </tr>
    </thead>
    <tbody>
      @forelse($orders as $index => $order)
        @php
          $statusClass = match($order->status) {
            'completed', 'selesai' => 'badge-completed',
            'cancelled', 'rejected', 'batal' => 'badge-cancelled',
            'processing', 'responsed', 'delivered', 'proses' => 'badge-processing',
            default => 'badge-pending',
          };
          $statusLabel = match($order->status) {
            'completed', 'selesai' => 'Selesai',
            'cancelled', 'batal' => 'Dibatalkan',
            'rejected' => 'Ditolak',
            'processing', 'proses' => 'Diproses',
            'delivered' => 'Dikirim',
            default => 'Menunggu',
          };
        @endphp
        <tr>
          <td class="text-center">{{ $index + 1 }}</td>
          <td><strong>{{ $order->order_code ?: ('ORD-' . $order->id) }}</strong></td>
          <td>{{ $order->created_at?->format('d/m/Y H:i') ?? '-' }}</td>
          <td>{{ $order->nama ?: ($order->user?->name ?? 'Pelanggan') }}</td>
          <td>{{ in_array($order->status, ['cancelled', 'batal', 'rejected', 'gagal', 'undelivered', 'unpicked'], true) ? '-' : ($order->delivery_type === 'delivery' ? 'Kirim' : 'Ambil Sendiri') }}</td>
          <td>{{ $order->payment_method ?: 'COD' }}</td>
          <td><span class="badge {{ $statusClass }}">{{ $statusLabel }}</span></td>
          <td class="text-right"><strong>{{ number_format((float)$order->total, 0, ',', '.') }}</strong></td>
        </tr>
      @empty
        <tr>
          <td colspan="8" class="text-center" style="padding: 20px; color: #94a3b8;">Tidak ada data transaksi.</td>
        </tr>
      @endforelse
    </tbody>
  </table>
</body>
</html>
