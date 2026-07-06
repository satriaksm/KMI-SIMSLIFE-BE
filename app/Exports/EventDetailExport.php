<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class EventDetailExport implements WithMultipleSheets
{
    use Exportable;

    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function sheets(): array
    {
        return [
            new EventSummarySheet($this->data),
            new EventMerchantsSheet($this->data),
            new EventProductsSheet($this->data),
            new EventCategoriesSheet($this->data),
            new EventVouchersSheet($this->data),
            new EventPaymentMethodsSheet($this->data),
        ];
    }
}

class EventSummarySheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    protected $data;
    public function __construct($data) { $this->data = $data; }
    public function title(): string { return 'Ringkasan Event'; }
    public function headings(): array { return ['Metrik', 'Nilai']; }
    public function array(): array
    {
        $s = $this->data['summary'];
        $b = $this->data['buyer_behavior'];
        $r = $this->data['rating'];
        return [
            ['ID Event', $this->data['event_id']],
            ['Nama Event', $this->data['event_name']],
            ['Total Transaksi', $s['total_transactions']],
            ['Pesanan Selesai', $s['completed_orders']],
            ['Pesanan Dibatalkan', $s['cancelled_orders']],
            ['Total Pendapatan', $s['total_revenue']],
            ['Total Diskon', $s['total_discount']],
            ['Rata-rata Nilai Transaksi', $s['avg_transaction']],
            ['Pembeli Unik', $s['unique_buyers']],
            ['UMKM Aktif (Ada Transaksi)', $s['active_merchants']],
            ['UMKM Inaktif', $s['inactive_merchants']],
            ['Penggunaan Voucher', $s['voucher_usage_count']],
            ['Tingkat Penggunaan Voucher (%)', $s['voucher_usage_rate']],
            ['Pembeli Berulang', $b['repeat_buyers']],
            ['Tingkat Pembelian Berulang (%)', $b['repeat_buyer_rate']],
            ['Rata-rata Belanja per Pembeli', $b['avg_spend_per_buyer']],
            ['Rata-rata Rating', $r['avg_rating']],
            ['Total Ulasan', $r['total_reviews']],
        ];
    }
}

class EventMerchantsSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    protected $data;
    public function __construct($data) { $this->data = $data; }
    public function title(): string { return 'Ranking UMKM'; }
    public function headings(): array { return ['Peringkat', 'Nama UMKM', 'Segmentasi', 'Total Transaksi', 'Total Pendapatan', 'Pembeli Unik']; }
    public function array(): array
    {
        $rows = [];
        $rank = 1;
        foreach ($this->data['top_merchants_revenue'] as $m) {
            $rows[] = [
                $rank++,
                $m['name'],
                $m['segmentation'],
                $m['total_orders'],
                $m['total_revenue'],
                $m['unique_buyers'],
            ];
        }
        return $rows;
    }
}

class EventProductsSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    protected $data;
    public function __construct($data) { $this->data = $data; }
    public function title(): string { return 'Produk Terlaris'; }
    public function headings(): array { return ['Peringkat', 'Nama Produk', 'Nama UMKM', 'Total Unit Terjual', 'Total Pendapatan']; }
    public function array(): array
    {
        $rows = [];
        $rank = 1;
        foreach ($this->data['top_products'] as $p) {
            $rows[] = [
                $rank++,
                $p['product_name'],
                $p['merchant_name'],
                $p['total_qty'],
                $p['total_revenue'],
            ];
        }
        return $rows;
    }
}

class EventCategoriesSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    protected $data;
    public function __construct($data) { $this->data = $data; }
    public function title(): string { return 'Kategori Terlaris'; }
    public function headings(): array { return ['Peringkat', 'Kategori', 'Total Unit Terjual', 'Total Pendapatan']; }
    public function array(): array
    {
        $rows = [];
        $rank = 1;
        foreach ($this->data['top_categories'] as $c) {
            $rows[] = [
                $rank++,
                $c['category_name'],
                $c['total_qty'],
                $c['total_revenue'],
            ];
        }
        return $rows;
    }
}

class EventVouchersSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    protected $data;
    public function __construct($data) { $this->data = $data; }
    public function title(): string { return 'Performa Voucher'; }
    public function headings(): array { return ['Kode Voucher', 'Nama Voucher', 'Tipe', 'Nilai', 'Jumlah Digunakan', 'Total Diskon Diberikan']; }
    public function array(): array
    {
        $rows = [];
        foreach ($this->data['voucher_stats'] as $v) {
            $rows[] = [
                $v['voucher_code'],
                $v['voucher_name'],
                $v['voucher_type'] == 'percent' ? 'Persentase' : 'Nominal',
                $v['value'],
                $v['usage_count'],
                $v['total_discount'],
            ];
        }
        return $rows;
    }
}

class EventPaymentMethodsSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    protected $data;
    public function __construct($data) { $this->data = $data; }
    public function title(): string { return 'Metode Pembayaran'; }
    public function headings(): array { return ['Metode Pembayaran', 'Jumlah Transaksi']; }
    public function array(): array
    {
        $rows = [];
        foreach ($this->data['payment_methods'] as $p) {
            $rows[] = [
                $p['payment_method'],
                $p['count'],
            ];
        }
        return $rows;
    }
}
