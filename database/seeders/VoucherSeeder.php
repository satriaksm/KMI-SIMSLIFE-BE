<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\Voucher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VoucherSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $merchants = Merchant::all();

        if ($merchants->isEmpty()) {
            $this->command->info('Tidak ada merchant yang ditemukan. Silakan jalankan MerchantSeeder terlebih dahulu.');
            return;
        }

        DB::beginTransaction();
        try {
            foreach ($merchants as $merchant) {
                // Voucher 1: Persentase Diskon (Misal: Diskon 20%)
                Voucher::updateOrCreate(
                    [
                        'merchant_id'  => $merchant->id,
                        'voucher_code' => 'DISKON20',
                    ],
                    [
                        'event_id'             => null,
                        'voucher_name'         => 'Diskon 20% ' . $merchant->name,
                        'voucher_status'       => 'active',
                        'is_hidden'            => false,
                        'voucher_type'         => 'percent',
                        'voucher_description'  => 'Dapatkan diskon 20% untuk setiap pembelian minimal Rp 50.000, maksimal diskon Rp 15.000.',
                        'voucher_start_date'   => now()->subDays(1)->format('Y-m-d'),
                        'voucher_end_date'     => now()->addDays(30)->format('Y-m-d'),
                        'value'                => 20.00,
                        'max_discount_amount'  => 15000.00,
                        'min_purchase_amount'  => 50000.00,
                        'usage_limit_per_user' => 1,
                        'usage_limit'          => 100,
                    ]
                );

                // Voucher 2: Potongan Harga Tetap (Misal: Potongan Rp 10.000)
                Voucher::updateOrCreate(
                    [
                        'merchant_id'  => $merchant->id,
                        'voucher_code' => 'HEMAT10K',
                    ],
                    [
                        'event_id'             => null,
                        'voucher_name'         => 'Potongan Langsung 10RB',
                        'voucher_status'       => 'active',
                        'is_hidden'            => false,
                        'voucher_type'         => 'fixed',
                        'voucher_description'  => 'Potongan harga langsung sebesar Rp 10.000 untuk setiap pembelian minimal Rp 100.000.',
                        'voucher_start_date'   => now()->subDays(1)->format('Y-m-d'),
                        'voucher_end_date'     => now()->addDays(30)->format('Y-m-d'),
                        'value'                => 10000.00,
                        'max_discount_amount'  => null,
                        'min_purchase_amount'  => 100000.00,
                        'usage_limit_per_user' => 2,
                        'usage_limit'          => 50,
                    ]
                );

                // Voucher 3: Diskon Ongkir (Kategori Voucher Ongkir bisa disimulasikan sebagai fixed jika belum ada fitur ongkir)
                // Voucher::updateOrCreate(
                //     [
                //         'merchant_id'  => $merchant->id,
                //         'voucher_code' => 'GRATISONGKIR',
                //     ],
                //     [
                //         'event_id'             => null,
                //         'voucher_name'         => 'Subsidi Ongkir ' . $merchant->name,
                //         'voucher_status'       => 'active',
                //         'is_secret'            => false,
                //         'voucher_type'         => 'fixed',
                //         'voucher_description'  => 'Subsidi ongkos kirim senilai Rp 5.000 dengan minimum belanja Rp 30.000.',
                //         'voucher_start_date'   => now()->subDays(1)->format('Y-m-d'),
                //         'voucher_end_date'     => now()->addDays(30)->format('Y-m-d'),
                //         'value'                => 5000.00,
                //         'max_discount_amount'  => null,
                //         'min_purchase_amount'  => 30000.00,
                //         'usage_limit_per_user' => 3,
                //         'usage_limit'          => 200,
                //     ]
                // );
            }

            DB::commit();
            $this->command->info('VoucherSeeder berhasil dijalankan!');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Error VoucherSeeder: ' . $e->getMessage());
        }
    }
}