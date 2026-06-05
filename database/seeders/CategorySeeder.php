<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Category;


class CategorySeeder extends Seeder
{
    public function run(): void
    {
        // Gunakan explicit `id` agar parent_id references selalu akurat
        // saat seeder dijalankan ulang. Truncate lebih dulu jika ingin reset penuh.

        $categories = [
            // ===================================================
            // KATEGORI UTAMA (ID 1–25)
            // Mencakup: Toko Retail, Kuliner, dan Jasa UMKM
            // ===================================================

            // Produk Konsumsi
            ['id' => 1, 'parent_id' => null, 'name' => 'Makanan', 'slug' => 'makanan', 'image_path' => 'categories/makanan.png'],
            ['id' => 2, 'parent_id' => null, 'name' => 'Minuman', 'slug' => 'minuman', 'image_path' => 'categories/minuman.png'],
            ['id' => 3, 'parent_id' => null, 'name' => 'Kuliner & Katering', 'slug' => 'kuliner-katering'],
            // Produk Umum
            ['id' => 4, 'parent_id' => null, 'name' => 'Elektronik & Gadget', 'slug' => 'elektronik', 'image_path' => 'categories/elektronik.png'],
            ['id' => 5, 'parent_id' => null, 'name' => 'Fashion & Pakaian', 'slug' => 'fashion', 'image_path' => 'categories/fashion.png'],
            ['id' => 6, 'parent_id' => null, 'name' => 'Kecantikan & Kesehatan', 'slug' => 'kecantikan-kesehatan'],
            ['id' => 7, 'parent_id' => null, 'name' => 'Peralatan Rumah Tangga', 'slug' => 'peralatan-rumah'],
            ['id' => 8, 'parent_id' => null, 'name' => 'Olahraga & Outdoor', 'slug' => 'olahraga'],
            ['id' => 9, 'parent_id' => null, 'name' => 'Otomotif & Kendaraan', 'slug' => 'otomotif'],
            ['id' => 10, 'parent_id' => null, 'name' => 'Ibu & Anak', 'slug' => 'ibu-anak'],
            ['id' => 11, 'parent_id' => null, 'name' => 'Hobi & Koleksi', 'slug' => 'hobi'],
            ['id' => 12, 'parent_id' => null, 'name' => 'Buku & Alat Tulis', 'slug' => 'buku-atk'],
            ['id' => 13, 'parent_id' => null, 'name' => 'Seni & Kerajinan', 'slug' => 'seni-kerajinan'],
            ['id' => 14, 'parent_id' => null, 'name' => 'Pertanian & Perkebunan', 'slug' => 'pertanian-perkebunan'],
            ['id' => 15, 'parent_id' => null, 'name' => 'Perlengkapan Pesta & Event', 'slug' => 'perlengkapan-pesta'],
            ['id' => 16, 'parent_id' => null, 'name' => 'Bahan Baku & Kemasan', 'slug' => 'bahan-baku-kemasan'],
            ['id' => 17, 'parent_id' => null, 'name' => 'Alat Industri & Kantor', 'slug' => 'industri-kantor'],
            ['id' => 18, 'parent_id' => null, 'name' => 'Tiket & Voucher', 'slug' => 'tiket-voucher'],
            // Jasa UMKM (non-produk)
            ['id' => 19, 'parent_id' => null, 'name' => 'Salon & Kecantikan', 'slug' => 'salon-kecantikan'],
            ['id' => 20, 'parent_id' => null, 'name' => 'Perbaikan & Servis', 'slug' => 'perbaikan-servis'],
            ['id' => 21, 'parent_id' => null, 'name' => 'Pendidikan & Kursus', 'slug' => 'pendidikan-kursus'],
            ['id' => 22, 'parent_id' => null, 'name' => 'Jasa Digital & Kreatif', 'slug' => 'jasa-digital-kreatif'],
            ['id' => 23, 'parent_id' => null, 'name' => 'Fotografi & Videografi', 'slug' => 'fotografi-videografi'],
            ['id' => 24, 'parent_id' => null, 'name' => 'Kebugaran & Wellness', 'slug' => 'kebugaran-wellness'],
            ['id' => 25, 'parent_id' => null, 'name' => 'Dekorasi & Desain Interior', 'slug' => 'dekorasi-desain'],
            // Kategori Utama Tambahan
            ['id' => 26, 'parent_id' => null, 'name' => 'Jasa & Layanan', 'slug' => 'jasa-layanan'],
            ['id' => 27, 'parent_id' => null, 'name' => 'Properti', 'slug' => 'properti'],

            // ===================================
            // Subkategori Makanan (Parent ID 1)
            // ===================================
            ['parent_id' => 1, 'name' => 'Makanan Ringan', 'slug' => 'makanan-ringan'],
            ['parent_id' => 1, 'name' => 'Sembako', 'slug' => 'sembako'],
            ['parent_id' => 1, 'name' => 'Frozen Food', 'slug' => 'frozen-food'],
            ['parent_id' => 1, 'name' => 'Bumbu Masak', 'slug' => 'bumbu-masak'],
            ['parent_id' => 1, 'name' => 'Makanan Instan', 'slug' => 'makanan-instan'],
            ['parent_id' => 1, 'name' => 'Makanan Kaleng', 'slug' => 'makanan-kaleng'],
            ['parent_id' => 1, 'name' => 'Sereal', 'slug' => 'sereal'],
            ['parent_id' => 1, 'name' => 'Produk Olahan Susu', 'slug' => 'olahan-susu-makanan'],
            ['parent_id' => 1, 'name' => 'Buah & Sayur Segar', 'slug' => 'buah-sayur'],
            ['parent_id' => 1, 'name' => 'Daging & Seafood', 'slug' => 'daging-seafood'],
            ['parent_id' => 1, 'name' => 'Katering & Nasi Kotak', 'slug' => 'katering'],
            ['parent_id' => 1, 'name' => 'Kue Basah & Tradisional', 'slug' => 'kue-basah'],
            ['parent_id' => 1, 'name' => 'Hampers & Parsel', 'slug' => 'hampers'],

            // ===================================
            // Subkategori Minuman (Parent ID 2)
            // ===================================
            ['parent_id' => 2, 'name' => 'Kopi', 'slug' => 'kopi'],
            ['parent_id' => 2, 'name' => 'Teh', 'slug' => 'teh'],
            ['parent_id' => 2, 'name' => 'Minuman Ringan', 'slug' => 'minuman-ringan'],
            ['parent_id' => 2, 'name' => 'Susu & Olahan', 'slug' => 'susu-olahan'],
            ['parent_id' => 2, 'name' => 'Jus & Sirup', 'slug' => 'jus-sirup'],
            ['parent_id' => 2, 'name' => 'Air Mineral', 'slug' => 'air-mineral'],
            ['parent_id' => 2, 'name' => 'Minuman Serbuk', 'slug' => 'minuman-serbuk'],
            ['parent_id' => 2, 'name' => 'Minuman Energi', 'slug' => 'minuman-energi'],

            // ===================================
            // Subkategori Elektronik & Gadget (Parent ID 4)
            // ===================================
            ['parent_id' => 4, 'name' => 'Smartphone', 'slug' => 'smartphone'],
            ['parent_id' => 4, 'name' => 'Laptop', 'slug' => 'laptop'],
            ['parent_id' => 4, 'name' => 'Aksesoris Handphone', 'slug' => 'aksesoris-handphone'],
            ['parent_id' => 4, 'name' => 'Aksesoris Komputer', 'slug' => 'aksesoris-komputer'],
            ['parent_id' => 4, 'name' => 'Televisi', 'slug' => 'televisi'],
            ['parent_id' => 4, 'name' => 'Kamera', 'slug' => 'kamera'],
            ['parent_id' => 4, 'name' => 'Audio', 'slug' => 'audio'],
            ['parent_id' => 4, 'name' => 'Gadget Wearable', 'slug' => 'gadget-wearable'],
            ['parent_id' => 4, 'name' => 'Peralatan Gaming', 'slug' => 'peralatan-gaming'],
            ['parent_id' => 4, 'name' => 'Sparepart Smartphone', 'slug' => 'sparepart-smartphone'],
            ['parent_id' => 4, 'name' => 'Peralatan Kantor Elektronik', 'slug' => 'kantor-elektronik'],
            ['parent_id' => 4, 'name' => 'Alat Rumah Tangga Elektronik', 'slug' => 'rumah-elektronik'],
            ['parent_id' => 4, 'name' => 'Smart Home', 'slug' => 'smart-home'],
            ['parent_id' => 4, 'name' => 'Komponen Komputer', 'slug' => 'komponen-komputer'],

            // ===================================
            // Subkategori Fashion & Pakaian (Parent ID 5)
            // Sub-subkategori menggunakan explicit ID agar child-nya bisa merujuk dengan benar
            // ===================================
            ['id' => 101, 'parent_id' => 5, 'name' => 'Pakaian Pria', 'slug' => 'pakaian-pria'],
            ['id' => 102, 'parent_id' => 5, 'name' => 'Pakaian Wanita', 'slug' => 'pakaian-wanita'],
            ['id' => 103, 'parent_id' => 5, 'name' => 'Sepatu', 'slug' => 'sepatu'],
            ['parent_id' => 5, 'name' => 'Tas', 'slug' => 'tas'],
            ['parent_id' => 5, 'name' => 'Aksesoris Fashion', 'slug' => 'aksesoris-fashion'],
            ['parent_id' => 5, 'name' => 'Pakaian Anak', 'slug' => 'pakaian-anak'],
            ['parent_id' => 5, 'name' => 'Perhiasan', 'slug' => 'perhiasan'],
            ['parent_id' => 5, 'name' => 'Jam Tangan', 'slug' => 'jam-tangan'],
            ['parent_id' => 5, 'name' => 'Fashion Muslim', 'slug' => 'fashion-muslim'],
            ['parent_id' => 5, 'name' => 'Batik & Tenun', 'slug' => 'batik-tenun'],
            ['parent_id' => 5, 'name' => 'Pakaian Adat', 'slug' => 'pakaian-adat'],
            // Sub-subkategori Pakaian Pria (Parent ID 101)
            ['parent_id' => 101, 'name' => 'Kemeja Pria', 'slug' => 'kemeja-pria'],
            ['parent_id' => 101, 'name' => 'Kaos Pria', 'slug' => 'kaos-pria'],
            ['parent_id' => 101, 'name' => 'Celana Pria', 'slug' => 'celana-pria'],
            // Sub-subkategori Pakaian Wanita (Parent ID 102)
            ['parent_id' => 102, 'name' => 'Dress Wanita', 'slug' => 'dress-wanita'],
            ['parent_id' => 102, 'name' => 'Outerwear Wanita', 'slug' => 'outerwear-wanita'],
            ['parent_id' => 102, 'name' => 'Rok Wanita', 'slug' => 'rok-wanita'],
            // Sub-subkategori Sepatu (Parent ID 103)
            ['parent_id' => 103, 'name' => 'Sepatu Olahraga', 'slug' => 'sepatu-olahraga'],
            ['parent_id' => 103, 'name' => 'Sandal', 'slug' => 'sandal'],

            // ===================================
            // Subkategori Kecantikan & Kesehatan (Parent ID 6)
            // ===================================
            ['parent_id' => 6, 'name' => 'Skincare', 'slug' => 'skincare'],
            ['parent_id' => 6, 'name' => 'Makeup', 'slug' => 'makeup'],
            ['parent_id' => 6, 'name' => 'Parfum', 'slug' => 'parfum'],
            ['parent_id' => 6, 'name' => 'Obat & Vitamin', 'slug' => 'obat-vitamin'],
            ['parent_id' => 6, 'name' => 'Perawatan Rambut', 'slug' => 'perawatan-rambut'],
            ['parent_id' => 6, 'name' => 'Perawatan Tubuh', 'slug' => 'perawatan-tubuh'],
            ['parent_id' => 6, 'name' => 'Alat Kesehatan', 'slug' => 'alat-kesehatan'],
            ['parent_id' => 6, 'name' => 'Perawatan Gigi & Mulut', 'slug' => 'perawatan-gigi-mulut'],
            ['parent_id' => 6, 'name' => 'Aksesoris Kecantikan', 'slug' => 'aksesoris-kecantikan'],

            // ===================================
            // Subkategori Peralatan Rumah Tangga (Parent ID 7)
            // ===================================
            ['parent_id' => 7, 'name' => 'Perabot Rumah', 'slug' => 'perabot-rumah'],
            ['parent_id' => 7, 'name' => 'Alat Dapur', 'slug' => 'alat-dapur'],
            ['parent_id' => 7, 'name' => 'Kamar Tidur', 'slug' => 'kamar-tidur'],
            ['parent_id' => 7, 'name' => 'Kamar Mandi', 'slug' => 'kamar-mandi'],
            ['parent_id' => 7, 'name' => 'Perkakas', 'slug' => 'perkakas'],
            ['parent_id' => 7, 'name' => 'Peralatan Kebersihan', 'slug' => 'peralatan-kebersihan'],
            ['parent_id' => 7, 'name' => 'Pencahayaan', 'slug' => 'pencahayaan'],
            ['parent_id' => 7, 'name' => 'Dekorasi Rumah', 'slug' => 'dekorasi-rumah'],
            ['parent_id' => 7, 'name' => 'Mesin Cuci & Pengering', 'slug' => 'mesin-cuci'],
            ['parent_id' => 7, 'name' => 'Penyimpanan Pakaian', 'slug' => 'penyimpanan-pakaian'],

            // ===================================
            // Subkategori Olahraga & Outdoor (Parent ID 8)
            // ===================================
            ['parent_id' => 8, 'name' => 'Sepeda', 'slug' => 'sepeda'],
            ['parent_id' => 8, 'name' => 'Gym & Fitness', 'slug' => 'gym-fitness'],
            ['parent_id' => 8, 'name' => 'Outdoor', 'slug' => 'outdoor'],
            ['parent_id' => 8, 'name' => 'Pakaian Olahraga', 'slug' => 'pakaian-olahraga'],
            ['parent_id' => 8, 'name' => 'Aksesoris Olahraga', 'slug' => 'aksesoris-olahraga'],
            ['parent_id' => 8, 'name' => 'Olahraga Air', 'slug' => 'olahraga-air'],
            ['parent_id' => 8, 'name' => 'Olahraga Tim', 'slug' => 'olahraga-tim'],

            // ===================================
            // Subkategori Otomotif & Kendaraan (Parent ID 9)
            // ===================================
            ['parent_id' => 9, 'name' => 'Aksesoris Motor', 'slug' => 'aksesoris-motor'],
            ['parent_id' => 9, 'name' => 'Aksesoris Mobil', 'slug' => 'aksesoris-mobil'],
            ['parent_id' => 9, 'name' => 'Sparepart', 'slug' => 'sparepart'],
            ['parent_id' => 9, 'name' => 'Oli & Pelumas', 'slug' => 'oli-pelumas'],
            ['parent_id' => 9, 'name' => 'Perawatan Kendaraan', 'slug' => 'perawatan-kendaraan'],
            ['parent_id' => 9, 'name' => 'Helm & Safety Riding', 'slug' => 'helm-safety'],
            ['parent_id' => 9, 'name' => 'Ban & Roda', 'slug' => 'ban-roda'],

            // ===================================
            // Subkategori Ibu & Anak (Parent ID 10)
            // ===================================
            ['parent_id' => 10, 'name' => 'Popok Bayi', 'slug' => 'popok-bayi'],
            ['parent_id' => 10, 'name' => 'Pakaian Bayi', 'slug' => 'pakaian-bayi'],
            ['parent_id' => 10, 'name' => 'Mainan Anak', 'slug' => 'mainan-anak'],
            ['parent_id' => 10, 'name' => 'Perlengkapan Makan Bayi', 'slug' => 'perlengkapan-makan-bayi'],
            ['parent_id' => 10, 'name' => 'Makanan Bayi & Susu', 'slug' => 'makanan-bayi-susu'],
            ['parent_id' => 10, 'name' => 'Perlengkapan Ibu Hamil', 'slug' => 'perlengkapan-ibu-hamil'],
            ['parent_id' => 10, 'name' => 'Stroller & Car Seat', 'slug' => 'stroller-car-seat'],

            // ===================================
            // Subkategori Hobi & Koleksi (Parent ID 11)
            // ===================================
            ['parent_id' => 11, 'name' => 'Musik', 'slug' => 'musik'],
            ['parent_id' => 11, 'name' => 'Koleksi', 'slug' => 'koleksi'],
            ['parent_id' => 11, 'name' => 'Game', 'slug' => 'game'],
            ['parent_id' => 11, 'name' => 'Fotografi', 'slug' => 'fotografi'],
            ['parent_id' => 11, 'name' => 'Kerajinan Tangan', 'slug' => 'kerajinan-tangan'],
            ['parent_id' => 11, 'name' => 'Hewan Peliharaan', 'slug' => 'hewan-peliharaan'],
            ['parent_id' => 11, 'name' => 'Alat Memancing', 'slug' => 'alat-memancing'],
            ['parent_id' => 11, 'name' => 'Aksesoris Musik', 'slug' => 'aksesoris-musik'],

            // ===================================
            // Subkategori Buku & Alat Tulis (Parent ID 12)
            // ===================================
            ['parent_id' => 12, 'name' => 'Novel', 'slug' => 'novel'],
            ['parent_id' => 12, 'name' => 'Komik', 'slug' => 'komik'],
            ['parent_id' => 12, 'name' => 'ATK', 'slug' => 'atk'],
            ['parent_id' => 12, 'name' => 'Buku Pelajaran', 'slug' => 'buku-pelajaran'],
            ['parent_id' => 12, 'name' => 'Majalah', 'slug' => 'majalah'],
            ['parent_id' => 12, 'name' => 'Buku Resep & Gaya Hidup', 'slug' => 'buku-gaya-hidup'],
            ['parent_id' => 12, 'name' => 'Peralatan Gambar Teknik', 'slug' => 'gambar-teknik'],

            // ===================================
            // Subkategori Seni & Kerajinan (Parent ID 13)
            // ===================================
            ['parent_id' => 13, 'name' => 'Alat Lukis', 'slug' => 'alat-lukis'],
            ['parent_id' => 13, 'name' => 'Bahan Kerajinan', 'slug' => 'bahan-kerajinan'],
            ['parent_id' => 13, 'name' => 'Kertas Khusus', 'slug' => 'kertas-khusus'],
            ['parent_id' => 13, 'name' => 'Peralatan Jahit', 'slug' => 'peralatan-jahit'],

            // ===================================
            // Subkategori Pertanian & Perkebunan (Parent ID 14)
            // ===================================
            ['parent_id' => 14, 'name' => 'Benih & Bibit', 'slug' => 'benih-bibit'],
            ['parent_id' => 14, 'name' => 'Pupuk & Pestisida', 'slug' => 'pupuk-pestisida'],
            ['parent_id' => 14, 'name' => 'Alat Kebun', 'slug' => 'alat-kebun'],
            ['parent_id' => 14, 'name' => 'Media Tanam', 'slug' => 'media-tanam'],

            // ===================================
            // Subkategori Perlengkapan Pesta & Event (Parent ID 15)
            // ===================================
            ['parent_id' => 15, 'name' => 'Balon & Dekorasi', 'slug' => 'balon-dekorasi'],
            ['parent_id' => 15, 'name' => 'Kostum Pesta', 'slug' => 'kostum-pesta'],
            ['parent_id' => 15, 'name' => 'Peralatan Kue', 'slug' => 'peralatan-kue'],
            ['parent_id' => 15, 'name' => 'Lilin & Korek Api', 'slug' => 'lilin-korek'],

            // ===================================
            // Subkategori Bahan Baku & Kemasan (Parent ID 16)
            // ===================================
            ['parent_id' => 16, 'name' => 'Kemasan Makanan', 'slug' => 'kemasan-makanan'],
            ['parent_id' => 16, 'name' => 'Kardus & Box', 'slug' => 'kardus-box'],
            ['parent_id' => 16, 'name' => 'Plastik & Bubble Wrap', 'slug' => 'plastik-bubble'],
            ['parent_id' => 16, 'name' => 'Bahan Kue (Baking)', 'slug' => 'bahan-kue-mentah'],
            ['parent_id' => 16, 'name' => 'Tepung & Biji-bijian', 'slug' => 'tepung-biji'],

            // ===================================
            // Subkategori Alat Industri & Kantor (Parent ID 17)
            // ===================================
            ['parent_id' => 17, 'name' => 'Mesin Produksi', 'slug' => 'mesin-produksi'],
            ['parent_id' => 17, 'name' => 'Alat Keselamatan Kerja', 'slug' => 'safety-k3'],
            ['parent_id' => 17, 'name' => 'Furniture Kantor', 'slug' => 'furniture-kantor'],
            ['parent_id' => 17, 'name' => 'Printer & Scanner', 'slug' => 'printer-scanner'],

            // ===================================
            // Subkategori Tiket & Voucher (Parent ID 18)
            // ===================================
            ['parent_id' => 18, 'name' => 'Pulsa & Data', 'slug' => 'pulsa-data'],
            ['parent_id' => 18, 'name' => 'Token Listrik & Tagihan', 'slug' => 'token-tagihan'],
            ['parent_id' => 18, 'name' => 'Voucher Game', 'slug' => 'voucher-game'],
            ['parent_id' => 18, 'name' => 'Tiket Event', 'slug' => 'tiket-event'],
            ['parent_id' => 18, 'name' => 'Tiket Wisata', 'slug' => 'tiket-wisata'],

            // ===================================
            // Subkategori Jasa & Layanan (Parent ID 26)
            // ===================================
            ['parent_id' => 26, 'name' => 'Jasa Digital', 'slug' => 'jasa-digital'],
            ['parent_id' => 26, 'name' => 'Jasa Perbaikan', 'slug' => 'jasa-perbaikan'],
            ['parent_id' => 26, 'name' => 'Jasa Kebersihan', 'slug' => 'jasa-kebersihan'],
            ['parent_id' => 26, 'name' => 'Jasa Sewa', 'slug' => 'jasa-sewa'],

            // ===================================
            // Subkategori Properti (Parent ID 27)
            // ===================================
            ['parent_id' => 27, 'name' => 'Rumah Dijual', 'slug' => 'rumah-dijual'],
            ['parent_id' => 27, 'name' => 'Apartemen Disewa', 'slug' => 'apartemen-disewa'],
            ['parent_id' => 27, 'name' => 'Tanah', 'slug' => 'tanah'],
        ];

        if (Schema::hasTable('categories')) {
            foreach ($categories as $category) {
                // Menggunakan insertOrIgnore untuk menghindari duplikasi jika seeder dijalankan lebih dari sekali pada data yang sama
                DB::table('categories')->insertOrIgnore($category);
            }
        }
    }
}
