<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\Voucher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class VoucherSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🎟️ Creating vouchers for merchants...');

        $merchants = Merchant::where('status', 'approved')->get();

        if ($merchants->isEmpty()) {
            $this->command->warn('⚠️ No approved merchants found. Run MerchantSeeder first.');
            return;
        }

        $voucherNameTemplates = [
            'Diskon Belanja',
            'Voucher Hemat',
            'Promo Spesial',
            'Potongan Harga',
            'Voucher UMKM',
        ];

        $created = 0;

        foreach ($merchants as $merchant) {
            // 2-5 vouchers per merchant
            $voucherCount = rand(2, 5);

            for ($i = 0; $i < $voucherCount; $i++) {
                $type = rand(1, 100) <= 70 ? 'percent' : 'fixed';

                $startDate = now()->subDays(rand(0, 7))->toDateString();
                $endDate = now()->addDays(rand(7, 45))->toDateString();

                $nameBase = $voucherNameTemplates[array_rand($voucherNameTemplates)];
                $voucherName = $nameBase . ' ' . Str::upper(Str::random(4));

                // Make sure voucher_code stays unique (unique index in DB)
                $voucherCode = $this->generateUniqueVoucherCode($merchant->id);

                $minPurchase = rand(0, 1) ? rand(0, 200_000) : 0;
                $usageLimitPerUser = rand(1, 3);
                $usageLimit = rand(1, 100) <= 30 ? null : rand(50, 500);

                if ($type === 'percent') {
                    $value = rand(5, 30); // percentage
                    $maxDiscount = rand(1, 100) <= 80 ? rand(10_000, 150_000) : null;
                } else {
                    $value = rand(5_000, 75_000); // fixed amount
                    $maxDiscount = null;
                }

                Voucher::create([
                    'merchant_id' => $merchant->id,
                    'event_id' => null,
                    'voucher_name' => $voucherName,
                    'voucher_code' => $voucherCode,
                    'voucher_status' => rand(1, 100) <= 85 ? 'active' : 'inactive',
                    'voucher_type' => $type,
                    'voucher_description' => 'Voucher otomatis untuk merchant ' . $merchant->name,
                    'voucher_start_date' => $startDate,
                    'voucher_end_date' => $endDate,
                    'value' => $value,
                    'max_discount_amount' => $maxDiscount,
                    'min_purchase_amount' => $minPurchase,
                    'usage_limit_per_user' => $usageLimitPerUser,
                    'usage_limit' => $usageLimit,
                ]);

                $created++;
            }
        }

        $this->command->info('Vouchers seeded successfully!');
        $this->command->info("   Total vouchers: {$created}");
        $this->command->info("   Merchants: {$merchants->count()}");
    }

    private function generateUniqueVoucherCode(int $merchantId): string
    {
        // Prefix keeps it readable while remaining unique
        // Example: VC-12-A1B2C3
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = 'VC-' . $merchantId . '-' . Str::upper(Str::random(6));

            if (!Voucher::where('voucher_code', $code)->exists()) {
                return $code;
            }
        }

        // Extremely unlikely fallback
        return 'VC-' . $merchantId . '-' . Str::upper(Str::random(12));
    }
}
