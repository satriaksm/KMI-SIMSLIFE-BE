<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
                // MasterDataSeeder::class,
            CategorySeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            SegmentationSeeder::class,
                // PaguyubanSeeder::class,
                // MerchantSeeder::class,
                // VoucherSeeder::class,
                // JasaSeeder::class,
                // ProductSeeder::class,
                // OrderSeeder::class,
                // CommunityPostSeeder::class,
                // PostCommentSeeder::class,
            ReportReasonSeeder::class,
            ShippingSettingSeeder::class,
            PaymentFeeSeeder::class,
            // ContentReportSeeder::class,
            // AddressSeeder::class,
            // EventSeeder::class,
        ]);
    }
}
