<?php

namespace Database\Seeders;

use App\Models\PaymentFee;
use Illuminate\Database\Seeder;

class PaymentFeeSeeder extends Seeder
{
    public function run()
    {
        $fees = [
            [
                'method_code' => 'QRIS',
                'method_name' => 'QRIS',
                'type' => 'percentage',
                'value' => 0.7,
                'description' => 'QRIS (GoPay, Dana, OVO, dll via QR)',
            ],
            [
                'method_code' => 'VA',
                'method_name' => 'VA (BCA, BNI, BRI, Mandiri, Permata, CIMB)',
                'type' => 'flat',
                'value' => 4440,
                'description' => 'Virtual Account Bank',
            ],
            [
                'method_code' => 'EWALLET',
                'method_name' => 'E-Wallet (OVO, DANA, LinkAja)',
                'type' => 'percentage',
                'value' => 1.5,
                'description' => 'E-Wallet langsung',
            ],
            [
                'method_code' => 'SHOPEEPAY',
                'method_name' => 'ShopeePay',
                'type' => 'percentage',
                'value' => 2.0,
                'description' => 'ShopeePay',
            ],
            [
                'method_code' => 'RETAIL',
                'method_name' => 'Retail (Alfamart, Indomaret)',
                'type' => 'flat',
                'value' => 5550,
                'description' => 'Pembayaran di gerai retail',
            ],
            [
                'method_code' => 'COD',
                'method_name' => 'COD',
                'type' => 'flat',
                'value' => 0,
                'description' => 'Bayar di tempat',
            ],
            [
                'method_code' => 'PAYOUT',
                'method_name' => 'Payout (Penarikan UMKM)',
                'type' => 'flat',
                'value' => 4440,
                'description' => 'Biaya transfer ke rekening UMKM',
            ],
        ];

        foreach ($fees as $fee) {
            PaymentFee::updateOrCreate(
                ['method_code' => $fee['method_code']],
                $fee
            );
        }
    }
}
