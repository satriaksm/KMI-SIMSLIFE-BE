<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $sqlPath = base_path('wilayah-data.sql');
        if (file_exists($sqlPath)) {
            $this->command->info('Mengimpor data wilayah dari wilayah-data.sql...');
            \Illuminate\Support\Facades\DB::unprepared(file_get_contents($sqlPath));
            $this->command->info('Data wilayah berhasil diimpor.');
        } else {
            $this->command->warn('File wilayah-data.sql tidak ditemukan.');
        }

        $this->call([
            CategorySeeder::class,
            RoleSeeder::class,
            SegmentationSeeder::class,
            ReportReasonSeeder::class,
            ShippingSettingSeeder::class,
            PaymentFeeSeeder::class,

            UserSeeder::class,
            MerchantSeeder::class,
            VoucherSeeder::class,
            // JasaSeeder::class,
            ProductSeeder::class,
            CommunityPostSeeder::class,
            PostCommentSeeder::class,
            // ContentReportSeeder::class,
            EventSeeder::class,
            EventAnalyticsSeeder::class,
        ]);
    }
}
