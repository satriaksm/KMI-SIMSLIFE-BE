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
            SegmentationSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
            RoleUserSeeder::class,
            JasaSeeder::class,
            PromoSeeder::class,
            PaguyubanSeeder::class,
            MerchantSeeder::class,
            ProductSeeder::class,
            CommunityPostSeeder::class,
            PostCommentSeeder::class,
            OrderSeeder::class,
            ReportReasonSeeder::class,
            ContentReportSeeder::class,
        ]);
    }
}
