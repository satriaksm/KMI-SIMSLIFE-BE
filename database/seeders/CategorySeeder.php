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

        $categories = [
            // ===================================
            // Kategori Utama yang Sudah Ada (ID 1-11)
            // ===================================
            ['parent_id' => null, 'name' => 'Makanan', 'slug' => 'makanan', 'image_path' => 'categories/makanan.png'], // 1
            ['parent_id' => null, 'name' => 'Minuman', 'slug' => 'minuman', 'image_path' => 'categories/minuman.png'], // 2
            ['parent_id' => null, 'name' => 'Elektronik', 'slug' => 'elektronik', 'image_path' => 'categories/elektronik.png'], // 3
            ['parent_id' => null, 'name' => 'Fashion', 'slug' => 'fashion', 'image_path' => 'categories/fashion.png'], // 4
            ['parent_id' => null, 'name' => 'Kecantikan & Kesehatan', 'slug' => 'kecantikan-kesehatan'], // 5
            ['parent_id' => null, 'name' => 'Peralatan Rumah Tangga', 'slug' => 'peralatan-rumah'], // 6
            ['parent_id' => null, 'name' => 'Olahraga', 'slug' => 'olahraga'], // 7
            ['parent_id' => null, 'name' => 'Otomotif', 'slug' => 'otomotif'], // 8
            ['parent_id' => null, 'name' => 'Ibu & Anak', 'slug' => 'ibu-anak'], // 9
            ['parent_id' => null, 'name' => 'Hobi', 'slug' => 'hobi'], // 10
            ['parent_id' => null, 'name' => 'Buku & Alat Tulis', 'slug' => 'buku-atk'], // 11

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
            // Tambahan
            ['parent_id' => 1, 'name' => 'Produk Olahan Susu', 'slug' => 'olahan-susu-makanan'],
            ['parent_id' => 1, 'name' => 'Buah & Sayur Segar', 'slug' => 'buah-sayur'],
            ['parent_id' => 1, 'name' => 'Daging & Seafood', 'slug' => 'daging-seafood'],

            // ===================================
            // Subkategori Minuman (Parent ID 2)
            // ===================================
            ['parent_id' => 2, 'name' => 'Kopi', 'slug' => 'kopi'],
            ['parent_id' => 2, 'name' => 'Teh', 'slug' => 'teh'],
            ['parent_id' => 2, 'name' => 'Minuman Ringan', 'slug' => 'minuman-ringan'],
            ['parent_id' => 2, 'name' => 'Susu & Olahan', 'slug' => 'susu-olahan'],
            ['parent_id' => 2, 'name' => 'Jus & Sirup', 'slug' => 'jus-sirup'],
            ['parent_id' => 2, 'name' => 'Air Mineral', 'slug' => 'air-mineral'],
            // Tambahan
            ['parent_id' => 2, 'name' => 'Minuman Serbuk', 'slug' => 'minuman-serbuk'],
            ['parent_id' => 2, 'name' => 'Minuman Energi', 'slug' => 'minuman-energi'],

            // ===================================
            // Subkategori Elektronik (Parent ID 3)
            // ===================================
            ['parent_id' => 3, 'name' => 'Smartphone', 'slug' => 'smartphone'], // 23 (contoh ID urutan)
            ['parent_id' => 3, 'name' => 'Laptop', 'slug' => 'laptop'],
            ['parent_id' => 3, 'name' => 'Aksesoris Handphone', 'slug' => 'aksesoris-handphone'],
            ['parent_id' => 3, 'name' => 'Aksesoris Komputer', 'slug' => 'aksesoris-komputer'],
            ['parent_id' => 3, 'name' => 'Televisi', 'slug' => 'televisi'],
            ['parent_id' => 3, 'name' => 'Kamera', 'slug' => 'kamera'],
            ['parent_id' => 3, 'name' => 'Audio', 'slug' => 'audio'],
            ['parent_id' => 3, 'name' => 'Gadget Wearable', 'slug' => 'gadget-wearable'],
            ['parent_id' => 3, 'name' => 'Peralatan Gaming', 'slug' => 'peralatan-gaming'],
            ['parent_id' => 3, 'name' => 'Sparepart Smartphone', 'slug' => 'sparepart-smartphone'],
            // Tambahan
            ['parent_id' => 3, 'name' => 'Peralatan Kantor Elektronik', 'slug' => 'kantor-elektronik'],
            ['parent_id' => 3, 'name' => 'Alat Rumah Tangga Elektronik', 'slug' => 'rumah-elektronik'],

            // ===================================
            // Subkategori Fashion (Parent ID 4)
            // ===================================
            ['parent_id' => 4, 'name' => 'Pakaian Pria', 'slug' => 'pakaian-pria'], // 35 (contoh ID urutan)
            ['parent_id' => 4, 'name' => 'Pakaian Wanita', 'slug' => 'pakaian-wanita'], // 36
            ['parent_id' => 4, 'name' => 'Sepatu', 'slug' => 'sepatu'],
            ['parent_id' => 4, 'name' => 'Tas', 'slug' => 'tas'],
            ['parent_id' => 4, 'name' => 'Aksesoris Fashion', 'slug' => 'aksesoris-fashion'],
            ['parent_id' => 4, 'name' => 'Pakaian Anak', 'slug' => 'pakaian-anak'],
            ['parent_id' => 4, 'name' => 'Perhiasan', 'slug' => 'perhiasan'],
            ['parent_id' => 4, 'name' => 'Jam Tangan', 'slug' => 'jam-tangan'],
            // Sub-subkategori Pria (Parent ID 35)
            ['parent_id' => 35, 'name' => 'Kemeja Pria', 'slug' => 'kemeja-pria'],
            ['parent_id' => 35, 'name' => 'Kaos Pria', 'slug' => 'kaos-pria'],
            // Sub-subkategori Wanita (Parent ID 36)
            ['parent_id' => 36, 'name' => 'Dress Wanita', 'slug' => 'dress-wanita'],
            ['parent_id' => 36, 'name' => 'Outerwear Wanita', 'slug' => 'outerwear-wanita'],
            // Tambahan Sub-sub Fashion
            ['parent_id' => 35, 'name' => 'Celana Pria', 'slug' => 'celana-pria'],
            ['parent_id' => 36, 'name' => 'Rok Wanita', 'slug' => 'rok-wanita'],
            ['parent_id' => 37, 'name' => 'Sepatu Olahraga', 'slug' => 'sepatu-olahraga'], // Parent: Sepatu (ID 37)
            ['parent_id' => 37, 'name' => 'Sandal', 'slug' => 'sandal'], // Parent: Sepatu (ID 37)


            // ===================================
            // Subkategori Kecantikan & Kesehatan (Parent ID 5)
            // ===================================
            ['parent_id' => 5, 'name' => 'Skincare', 'slug' => 'skincare'],
            ['parent_id' => 5, 'name' => 'Makeup', 'slug' => 'makeup'],
            ['parent_id' => 5, 'name' => 'Parfum', 'slug' => 'parfum'],
            ['parent_id' => 5, 'name' => 'Obat & Vitamin', 'slug' => 'obat-vitamin'],
            ['parent_id' => 5, 'name' => 'Perawatan Rambut', 'slug' => 'perawatan-rambut'],
            ['parent_id' => 5, 'name' => 'Perawatan Tubuh', 'slug' => 'perawatan-tubuh'],
            ['parent_id' => 5, 'name' => 'Alat Kesehatan', 'slug' => 'alat-kesehatan'],
            // Tambahan
            ['parent_id' => 5, 'name' => 'Perawatan Gigi & Mulut', 'slug' => 'perawatan-gigi-mulut'],
            ['parent_id' => 5, 'name' => 'Aksesoris Kecantikan', 'slug' => 'aksesoris-kecantikan'],

            // ===================================
            // Subkategori Peralatan Rumah Tangga (Parent ID 6)
            // ===================================
            ['parent_id' => 6, 'name' => 'Perabot Rumah', 'slug' => 'perabot-rumah'],
            ['parent_id' => 6, 'name' => 'Alat Dapur', 'slug' => 'alat-dapur'],
            ['parent_id' => 6, 'name' => 'Kamar Tidur', 'slug' => 'kamar-tidur'],
            ['parent_id' => 6, 'name' => 'Kamar Mandi', 'slug' => 'kamar-mandi'],
            ['parent_id' => 6, 'name' => 'Perkakas', 'slug' => 'perkakas'],
            ['parent_id' => 6, 'name' => 'Peralatan Kebersihan', 'slug' => 'peralatan-kebersihan'],
            ['parent_id' => 6, 'name' => 'Pencahayaan', 'slug' => 'pencahayaan'],
            ['parent_id' => 6, 'name' => 'Dekorasi Rumah', 'slug' => 'dekorasi-rumah'],
            // Tambahan
            ['parent_id' => 6, 'name' => 'Mesin Cuci & Pengering', 'slug' => 'mesin-cuci'],
            ['parent_id' => 6, 'name' => 'Penyimpanan Pakaian', 'slug' => 'penyimpanan-pakaian'],

            // ===================================
            // Subkategori Olahraga (Parent ID 7)
            // ===================================
            ['parent_id' => 7, 'name' => 'Sepeda', 'slug' => 'sepeda'],
            ['parent_id' => 7, 'name' => 'Gym & Fitness', 'slug' => 'gym-fitness'],
            ['parent_id' => 7, 'name' => 'Outdoor', 'slug' => 'outdoor'],
            ['parent_id' => 7, 'name' => 'Pakaian Olahraga', 'slug' => 'pakaian-olahraga'],
            ['parent_id' => 7, 'name' => 'Aksesoris Olahraga', 'slug' => 'aksesoris-olahraga'],
            // Tambahan
            ['parent_id' => 7, 'name' => 'Olahraga Air', 'slug' => 'olahraga-air'],
            ['parent_id' => 7, 'name' => 'Olahraga Tim', 'slug' => 'olahraga-tim'],

            // ===================================
            // Subkategori Otomotif (Parent ID 8)
            // ===================================
            ['parent_id' => 8, 'name' => 'Aksesoris Motor', 'slug' => 'aksesoris-motor'],
            ['parent_id' => 8, 'name' => 'Aksesoris Mobil', 'slug' => 'aksesoris-mobil'],
            ['parent_id' => 8, 'name' => 'Sparepart', 'slug' => 'sparepart'],
            ['parent_id' => 8, 'name' => 'Oli & Pelumas', 'slug' => 'oli-pelumas'],
            ['parent_id' => 8, 'name' => 'Perawatan Kendaraan', 'slug' => 'perawatan-kendaraan'],
            // Tambahan
            ['parent_id' => 8, 'name' => 'Helm & Safety Riding', 'slug' => 'helm-safety'],
            ['parent_id' => 8, 'name' => 'Ban & Roda', 'slug' => 'ban-roda'],

            // ===================================
            // Subkategori Ibu & Anak (Parent ID 9)
            // ===================================
            ['parent_id' => 9, 'name' => 'Popok Bayi', 'slug' => 'popok-bayi'],
            ['parent_id' => 9, 'name' => 'Pakaian Bayi', 'slug' => 'pakaian-bayi'],
            ['parent_id' => 9, 'name' => 'Mainan Anak', 'slug' => 'mainan-anak'],
            ['parent_id' => 9, 'name' => 'Perlengkapan Makan Bayi', 'slug' => 'perlengkapan-makan-bayi'],
            ['parent_id' => 9, 'name' => 'Makanan Bayi & Susu', 'slug' => 'makanan-bayi-susu'],
            // Tambahan
            ['parent_id' => 9, 'name' => 'Perlengkapan Ibu Hamil', 'slug' => 'perlengkapan-ibu-hamil'],
            ['parent_id' => 9, 'name' => 'Stroller & Car Seat', 'slug' => 'stroller-car-seat'],

            // ===================================
            // Subkategori Hobi (Parent ID 10)
            // ===================================
            ['parent_id' => 10, 'name' => 'Musik', 'slug' => 'musik'],
            ['parent_id' => 10, 'name' => 'Koleksi', 'slug' => 'koleksi'],
            ['parent_id' => 10, 'name' => 'Game', 'slug' => 'game'],
            ['parent_id' => 10, 'name' => 'Fotografi', 'slug' => 'fotografi'],
            ['parent_id' => 10, 'name' => 'Kerajinan Tangan', 'slug' => 'kerajinan-tangan'],
            ['parent_id' => 10, 'name' => 'Hewan Peliharaan', 'slug' => 'hewan-peliharaan'],
            // Tambahan
            ['parent_id' => 10, 'name' => 'Alat Memancing', 'slug' => 'alat-memancing'],
            ['parent_id' => 10, 'name' => 'Aksesoris Musik', 'slug' => 'aksesoris-musik'],

            // ===================================
            // Subkategori Buku & ATK (Parent ID 11)
            // ===================================
            ['parent_id' => 11, 'name' => 'Novel', 'slug' => 'novel'],
            ['parent_id' => 11, 'name' => 'Komik', 'slug' => 'komik'],
            ['parent_id' => 11, 'name' => 'ATK', 'slug' => 'atk'],
            ['parent_id' => 11, 'name' => 'Buku Pelajaran', 'slug' => 'buku-pelajaran'],
            ['parent_id' => 11, 'name' => 'Majalah', 'slug' => 'majalah'],
            // Tambahan
            ['parent_id' => 11, 'name' => 'Buku Resep & Gaya Hidup', 'slug' => 'buku-gaya-hidup'],
            ['parent_id' => 11, 'name' => 'Peralatan Gambar Teknik', 'slug' => 'gambar-teknik'],

            // ===================================
            // Kategori Utama BARU dari Sebelumnya (ID 12-14)
            // ===================================
            ['parent_id' => null, 'name' => 'Seni & Desain', 'slug' => 'seni-desain'], // 12
            ['parent_id' => null, 'name' => 'Perlengkapan Pesta', 'slug' => 'perlengkapan-pesta'], // 13
            ['parent_id' => null, 'name' => 'Pertanian & Berkebun', 'slug' => 'pertanian-berkebun'], // 14

            // Subkategori Seni & Desain (Parent ID 12)
            ['parent_id' => 12, 'name' => 'Alat Lukis', 'slug' => 'alat-lukis'],
            ['parent_id' => 12, 'name' => 'Bahan Kerajinan', 'slug' => 'bahan-kerajinan'],
            ['parent_id' => 12, 'name' => 'Kertas Khusus', 'slug' => 'kertas-khusus'],
            // Tambahan
            ['parent_id' => 12, 'name' => 'Peralatan Jahit', 'slug' => 'peralatan-jahit'],

            // Subkategori Perlengkapan Pesta (Parent ID 13)
            ['parent_id' => 13, 'name' => 'Balon & Dekorasi', 'slug' => 'balon-dekorasi'],
            ['parent_id' => 13, 'name' => 'Kostum Pesta', 'slug' => 'kostum-pesta'],
            ['parent_id' => 13, 'name' => 'Peralatan Kue', 'slug' => 'peralatan-kue'],
            // Tambahan
            ['parent_id' => 13, 'name' => 'Lilin & Korek Api', 'slug' => 'lilin-korek'],

            // Subkategori Pertanian & Berkebun (Parent ID 14)
            ['parent_id' => 14, 'name' => 'Benih & Bibit', 'slug' => 'benih-bibit'],
            ['parent_id' => 14, 'name' => 'Pupuk & Pestisida', 'slug' => 'pupuk-pestisida'],
            ['parent_id' => 14, 'name' => 'Alat Kebun', 'slug' => 'alat-kebun'],
            // Tambahan
            ['parent_id' => 14, 'name' => 'Media Tanam', 'slug' => 'media-tanam'],

            // ===================================
            // Kategori Utama BARU (Mulai ID 15)
            // ===================================
            ['parent_id' => null, 'name' => 'Jasa & Layanan', 'slug' => 'jasa-layanan'], // 15
            ['parent_id' => null, 'name' => 'Properti', 'slug' => 'properti'], // 16

            // Subkategori Jasa & Layanan (Parent ID 15)
            ['parent_id' => 15, 'name' => 'Jasa Digital', 'slug' => 'jasa-digital'],
            ['parent_id' => 15, 'name' => 'Jasa Perbaikan', 'slug' => 'jasa-perbaikan'],
            ['parent_id' => 15, 'name' => 'Jasa Kebersihan', 'slug' => 'jasa-kebersihan'],
            ['parent_id' => 15, 'name' => 'Jasa Sewa', 'slug' => 'jasa-sewa'],

            // Subkategori Properti (Parent ID 16)
            ['parent_id' => 16, 'name' => 'Rumah Dijual', 'slug' => 'rumah-dijual'],
            ['parent_id' => 16, 'name' => 'Apartemen Disewa', 'slug' => 'apartemen-disewa'],
            ['parent_id' => 16, 'name' => 'Tanah', 'slug' => 'tanah'],

            // Kategori Utama BARU (Mulai ID 17)
            // ===================================
            ['parent_id' => null, 'name' => 'Tiket & Voucher', 'slug' => 'tiket-voucher'], // 17
            ['parent_id' => null, 'name' => 'Bahan Baku & Kemasan', 'slug' => 'bahan-baku-kemasan'], // 18 (Penting untuk UMKM)
            ['parent_id' => null, 'name' => 'Alat Industri & Kantor', 'slug' => 'industri-kantor'], // 19

            // ===================================
            // EXPANSI KATEGORI LAMA (Subkategori Tambahan)
            // ===================================

            // Tambahan untuk Fashion (ID 4) - Fokus Fashion Muslim & Tradisional
            ['parent_id' => 4, 'name' => 'Fashion Muslim', 'slug' => 'fashion-muslim'], // ID Baru misal 100
            ['parent_id' => 4, 'name' => 'Batik & Tenun', 'slug' => 'batik-tenun'],     // ID Baru misal 101
            ['parent_id' => 4, 'name' => 'Pakaian Adat', 'slug' => 'pakaian-adat'],

            // Tambahan untuk Elektronik (ID 3) - Fokus Smart Home
            ['parent_id' => 3, 'name' => 'Smart Home', 'slug' => 'smart-home'], // CCTV, Smart Bulb, dll
            ['parent_id' => 3, 'name' => 'Komponen Komputer', 'slug' => 'komponen-komputer'], // VGA, RAM, SSD

            // Tambahan untuk Makanan (ID 1) - Fokus UMKM Katering
            ['parent_id' => 1, 'name' => 'Katering & Nasi Kotak', 'slug' => 'katering'],
            ['parent_id' => 1, 'name' => 'Kue Basah & Tradisional', 'slug' => 'kue-basah'],
            ['parent_id' => 1, 'name' => 'Hampers & Parsel', 'slug' => 'hampers'],


            // Subkategori Tiket & Voucher (Parent ID 17)
            ['parent_id' => 17, 'name' => 'Pulsa & Data', 'slug' => 'pulsa-data'],
            ['parent_id' => 17, 'name' => 'Token Listrik & Tagihan', 'slug' => 'token-tagihan'],
            ['parent_id' => 17, 'name' => 'Voucher Game', 'slug' => 'voucher-game'],
            ['parent_id' => 17, 'name' => 'Tiket Event', 'slug' => 'tiket-event'],
            ['parent_id' => 17, 'name' => 'Tiket Wisata', 'slug' => 'tiket-wisata'],

            // Subkategori Bahan Baku & Kemasan (Parent ID 18) - SUPER PENTING UNTUK UMKM
            ['parent_id' => 18, 'name' => 'Kemasan Makanan', 'slug' => 'kemasan-makanan'], // Thinwall, Paperbowl
            ['parent_id' => 18, 'name' => 'Kardus & Box', 'slug' => 'kardus-box'],
            ['parent_id' => 18, 'name' => 'Plastik & Bubble Wrap', 'slug' => 'plastik-bubble'],
            ['parent_id' => 18, 'name' => 'Bahan Kue (Baking)', 'slug' => 'bahan-kue-mentah'], // Tepung, Coklat blok
            ['parent_id' => 18, 'name' => 'Tepung & Biji-bijian', 'slug' => 'tepung-biji'],

            // Subkategori Alat Industri & Kantor (Parent ID 19)
            ['parent_id' => 19, 'name' => 'Mesin Produksi', 'slug' => 'mesin-produksi'], // Mesin Sealer, Vacuum
            ['parent_id' => 19, 'name' => 'Alat Keselamatan Kerja', 'slug' => 'safety-k3'],
            ['parent_id' => 19, 'name' => 'Furniture Kantor', 'slug' => 'furniture-kantor'],
            ['parent_id' => 19, 'name' => 'Printer & Scanner', 'slug' => 'printer-scanner'],
        ];

        if (Schema::hasTable('categories')) {
            foreach ($categories as $category) {
                // Menggunakan insertOrIgnore untuk menghindari duplikasi jika seeder dijalankan lebih dari sekali pada data yang sama
                DB::table('categories')->insertOrIgnore($category);
            }
        }
    }
}
