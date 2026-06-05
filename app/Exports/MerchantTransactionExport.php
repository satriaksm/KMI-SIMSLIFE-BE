<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MerchantTransactionExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    protected $transactions;

    public function __construct(Collection $transactions)
    {
        $this->transactions = $transactions;
    }

    public function collection()
    {
        return $this->transactions;
    }

    public function headings(): array
    {
        return [
            'Order ID',
            'Tanggal',
            'Pembeli',
            'Tipe Pengiriman',
            'Metode Pembayaran',
            'Status',
            'Gross Amount (Rp)',
            'Platform Fee (Rp)',
            'Net Amount / Pendapatan Bersih (Rp)'
        ];
    }

    public function map($order): array
    {
        return [
            $order->order_code,
            $order->created_at->format('Y-m-d H:i:s'),
            $order->user_name_snapshot,
            strtoupper($order->delivery_type),
            $order->payment_method,
            strtoupper($order->status),
            $order->gross_amount,
            $order->platform_fee,
            $order->net_amount,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1    => ['font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']], 'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4F46E5']]],
        ];
    }
}
