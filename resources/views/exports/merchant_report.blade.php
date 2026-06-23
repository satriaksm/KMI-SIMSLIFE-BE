<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Transaksi UMKM</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h2 { margin: 0; }
        .header p { margin: 5px 0; color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f4f4f4; }
        .text-right { text-align: right; }
        .summary { margin-top: 20px; float: right; width: 300px; }
        .summary table { border: none; }
        .summary th, .summary td { border: none; padding: 5px; }
        .summary th { text-align: left; background-color: transparent; }
        .clearfix::after { content: ""; clear: both; display: table; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Laporan Transaksi UMKM</h2>
        <h3>{{ $merchant->name }}</h3>
        <p>Periode: {{ $startDate ? \Carbon\Carbon::parse($startDate)->format('d/m/Y') : 'Awal' }} - {{ $endDate ? \Carbon\Carbon::parse($endDate)->format('d/m/Y') : 'Sekarang' }}</p>
        <p>Status Filter: {{ strtoupper($status) }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Order ID</th>
                <th>Pembeli</th>
                <th>Status</th>
                <th class="text-right">Gross Amount</th>
                <th class="text-right">Fee</th>
                <th class="text-right">Net Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transactions as $order)
                <tr>
                    <td>{{ $order->created_at->format('d/m/Y H:i') }}</td>
                    <td>{{ $order->order_code }}</td>
                    <td>{{ $order->user_name_snapshot }}</td>
                    <td>{{ strtoupper($order->status) }}</td>
                    <td class="text-right">Rp {{ number_format($order->gross_amount, 0, ',', '.') }}</td>
                    <td class="text-right">Rp {{ number_format($order->platform_fee, 0, ',', '.') }}</td>
                    <td class="text-right">Rp {{ number_format($order->net_amount, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align: center;">Tidak ada transaksi pada periode ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="summary clearfix">
        <table>
            <tr>
                <th>Total Transaksi</th>
                <td class="text-right">{{ $transactions->count() }}</td>
            </tr>
            <tr>
                <th>Total Pendapatan (Status Selesai)</th>
                <td class="text-right font-bold">Rp {{ number_format($totalRevenue, 0, ',', '.') }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
