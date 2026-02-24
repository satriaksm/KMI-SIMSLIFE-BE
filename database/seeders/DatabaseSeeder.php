<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            MasterDataSeeder::class,
            CategorySeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            SegmentationSeeder::class,
            MerchantSeeder::class,
            JasaSeeder::class,
            ChatSeeder::class,
            ProductSeeder::class,
            VoucherSeeder::class,
            RatingSeeder::class,
            // PaguyubanSeeder::class,
            // PromoSeeder::class,
            // OrderSeeder::class,
            // CommunityPostSeeder::class,
            // PostCommentSeeder::class,
            ReportReasonSeeder::class,
            // ContentReportSeeder::class,
            // AddressSeeder::class,
            // EventSeeder::class,
        ]);
    }
}
