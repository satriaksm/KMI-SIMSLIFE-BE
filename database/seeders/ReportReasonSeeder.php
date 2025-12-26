<?php

namespace Database\Seeders;

use App\Models\ReportReason;
use Illuminate\Database\Seeder;

class ReportReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            // Post reasons
            ['reason_title' => 'Konten Spam', 'applies_to' => 'post', 'is_active' => true],
            ['reason_title' => 'Konten Tidak Pantas', 'applies_to' => 'post', 'is_active' => true],
            ['reason_title' => 'Informasi Palsu', 'applies_to' => 'post', 'is_active' => true],
            ['reason_title' => 'Promosi Berlebihan', 'applies_to' => 'post', 'is_active' => true],

            // Comment reasons
            ['reason_title' => 'Komentar Spam', 'applies_to' => 'post_comment', 'is_active' => true],
            ['reason_title' => 'Ujaran Kebencian', 'applies_to' => 'post_comment', 'is_active' => true],
            ['reason_title' => 'Bullying/Harassment', 'applies_to' => 'post_comment', 'is_active' => true],

            // Product reasons
            ['reason_title' => 'Produk Palsu', 'applies_to' => 'product', 'is_active' => true],
            ['reason_title' => 'Harga Tidak Sesuai', 'applies_to' => 'product', 'is_active' => true],
            ['reason_title' => 'Deskripsi Menyesatkan', 'applies_to' => 'product', 'is_active' => true],

            // Merchant reasons
            ['reason_title' => 'Merchant Tidak Responsif', 'applies_to' => 'merchant', 'is_active' => true],
            ['reason_title' => 'Penipuan', 'applies_to' => 'merchant', 'is_active' => true],
        ];

        foreach ($reasons as $reason) {
            ReportReason::create($reason);
        }

        $this->command->info('Report reasons seeded successfully!');
    }
}