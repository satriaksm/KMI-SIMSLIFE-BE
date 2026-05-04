<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ReportReason;

class ReportReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            // Product Reports
            [
                'reason_title' => 'Produk tidak sesuai deskripsi',
                'reason_description' => 'Produk yang diterima tidak sesuai dengan deskripsi atau gambar di katalog',
                'applies_to' => 'product',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Gambar menyesatkan',
                'reason_description' => 'Gambar produk tidak menampilkan kondisi sebenarnya',
                'applies_to' => 'product',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Harga tidak wajar',
                'reason_description' => 'Harga produk terlalu tinggi atau mencurigakan',
                'applies_to' => 'product',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Produk ilegal/terlarang',
                'reason_description' => 'Produk yang dijual melanggar hukum atau kebijakan platform',
                'applies_to' => 'product',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Lainnya',
                'reason_description' => 'Alasan lain yang tidak tercantum (harap sertakan keterangan)',
                'applies_to' => 'product',
                'is_active' => true,
            ],

            // Merchant Reports
            [
                'reason_title' => 'UMKM tidak responsif',
                'reason_description' => 'UMKM tidak merespons pertanyaan atau pesanan',
                'applies_to' => 'merchant',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Merchant melanggar kebijakan',
                'reason_description' => 'UMKM melakukan tindakan yang melanggar aturan platform',
                'applies_to' => 'merchant',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Informasi palsu',
                'reason_description' => 'Informasi UMKM tidak akurat atau palsu',
                'applies_to' => 'merchant',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Penipuan',
                'reason_description' => 'UMKM diduga melakukan penipuan terhadap pembeli',
                'applies_to' => 'merchant',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Lainnya',
                'reason_description' => 'Alasan lain yang tidak tercantum (harap sertakan keterangan)',
                'applies_to' => 'merchant',
                'is_active' => true,
            ],

            // Post Reports
            [
                'reason_title' => 'Konten tidak pantas',
                'reason_description' => 'Postingan mengandung konten yang tidak pantas atau vulgar',
                'applies_to' => 'post',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Ujaran kebencian',
                'reason_description' => 'Postingan mengandung ujaran kebencian atau hasutan',
                'applies_to' => 'post',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Spam',
                'reason_description' => 'Postingan berisi spam atau iklan tidak relevan',
                'applies_to' => 'post',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Informasi menyesatkan',
                'reason_description' => 'Postingan berisi informasi palsu atau menyesatkan (hoax)',
                'applies_to' => 'post',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Kekerasan atau ancaman',
                'reason_description' => 'Postingan mengandung ancaman kekerasan atau intimidasi',
                'applies_to' => 'post',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Lainnya',
                'reason_description' => 'Alasan lain yang tidak tercantum (harap sertakan keterangan)',
                'applies_to' => 'post',
                'is_active' => true,
            ],

            // Comment Reports
            [
                'reason_title' => 'Ujaran kebencian',
                'reason_description' => 'Komentar mengandung ujaran kebencian',
                'applies_to' => 'post_comment',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Spam',
                'reason_description' => 'Komentar berisi spam atau iklan',
                'applies_to' => 'post_comment',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Rasis/SARA',
                'reason_description' => 'Komentar mengandung unsur SARA atau rasisme',
                'applies_to' => 'post_comment',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Bullying/harassment',
                'reason_description' => 'Komentar berisi pelecehan atau perundungan',
                'applies_to' => 'post_comment',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Konten tidak pantas',
                'reason_description' => 'Komentar mengandung konten vulgar atau tidak pantas',
                'applies_to' => 'post_comment',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Lainnya',
                'reason_description' => 'Alasan lain yang tidak tercantum (harap sertakan keterangan)',
                'applies_to' => 'post_comment',
                'is_active' => true,
            ],

            // User Reports
            [
                'reason_title' => 'Perilaku mencurigakan',
                'reason_description' => 'User menunjukkan perilaku yang mencurigakan atau tidak wajar',
                'applies_to' => 'user',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Akun palsu',
                'reason_description' => 'Akun diduga palsu atau menyamar sebagai orang lain',
                'applies_to' => 'user',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Penyalahgunaan platform',
                'reason_description' => 'User menyalahgunakan fitur atau kebijakan platform',
                'applies_to' => 'user',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Penipuan',
                'reason_description' => 'User diduga melakukan tindakan penipuan',
                'applies_to' => 'user',
                'is_active' => true,
            ],
            [
                'reason_title' => 'Lainnya',
                'reason_description' => 'Alasan lain yang tidak tercantum (harap sertakan keterangan)',
                'applies_to' => 'user',
                'is_active' => true,
            ],
        ];

        foreach ($reasons as $reason) {
            ReportReason::updateOrCreate(
                [
                    'reason_title' => $reason['reason_title'],
                    'applies_to' => $reason['applies_to'],
                ],
                $reason
            );
        }
    }
}