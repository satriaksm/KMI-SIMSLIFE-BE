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
            // KATEGORI UTAMA
            // ===================================================
            ['id' => 1, 'parent_id' => null, 'name' => 'Makanan', 'slug' => 'makanan', 'image_path' => 'categories/makanan.png'],
            ['id' => 2, 'parent_id' => null, 'name' => 'Minuman', 'slug' => 'minuman', 'image_path' => 'categories/minuman.png'],
            ['id' => 3, 'parent_id' => null, 'name' => 'Kuliner & Katering', 'slug' => 'kuliner-katering'],
            ['id' => 4, 'parent_id' => null, 'name' => 'Elektronik & Gadget', 'slug' => 'elektronik', 'image_path' => 'categories/elektronik.png'],
            ['id' => 5, 'parent_id' => null, 'name' => 'Fashion & Pakaian', 'slug' => 'fashion', 'image_path' => 'categories/fashion.png'],
            ['id' => 6, 'parent_id' => null, 'name' => 'Kecantikan & Kesehatan', 'slug' => 'kecantikan-kesehatan'],
            ['id' => 7, 'parent_id' => null, 'name' => 'Peralatan Rumah Tangga', 'slug' => 'peralatan-rumah'],
            ['id' => 8, 'parent_id' => null, 'name' => 'Olahraga & Outdoor', 'slug' => 'olahraga'],
            ['id' => 9, 'parent_id' => null, 'name' => 'Otomotif & Kendaraan', 'slug' => 'otomotif'],
            ['id' => 10, 'parent_id' => null, 'name' => 'Ibu & Bayi', 'slug' => 'ibu-bayi'],
            ['id' => 11, 'parent_id' => null, 'name' => 'Hobi & Koleksi', 'slug' => 'hobi'],
            ['id' => 12, 'parent_id' => null, 'name' => 'Buku & Alat Tulis', 'slug' => 'buku-atk'],
            ['id' => 13, 'parent_id' => null, 'name' => 'Seni & Kerajinan', 'slug' => 'seni-kerajinan'],
            ['id' => 14, 'parent_id' => null, 'name' => 'Pertanian & Hewan', 'slug' => 'pertanian-hewan'],
            ['id' => 15, 'parent_id' => null, 'name' => 'Perlengkapan Pesta', 'slug' => 'perlengkapan-pesta'],
            ['id' => 16, 'parent_id' => null, 'name' => 'Bahan Baku & Kemasan', 'slug' => 'bahan-baku-kemasan'],
            ['id' => 17, 'parent_id' => null, 'name' => 'Alat Industri & Kantor', 'slug' => 'industri-kantor'],
            ['id' => 18, 'parent_id' => null, 'name' => 'Tiket & Voucher', 'slug' => 'tiket-voucher'],
            // Jasa UMKM (non-produk)
            ['id' => 19, 'parent_id' => null, 'name' => 'Salon & Kecantikan', 'slug' => 'salon-kecantikan'],
            ['id' => 20, 'parent_id' => null, 'name' => 'Perbaikan & Servis', 'slug' => 'perbaikan-servis'],
            ['id' => 21, 'parent_id' => null, 'name' => 'Pendidikan & Kursus', 'slug' => 'pendidikan-kursus'],
            ['id' => 23, 'parent_id' => null, 'name' => 'Fotografi & Videografi', 'slug' => 'fotografi-videografi'],
            ['id' => 24, 'parent_id' => null, 'name' => 'Kebugaran & Wellness', 'slug' => 'kebugaran-wellness'],
            ['id' => 25, 'parent_id' => null, 'name' => 'Dekorasi & Desain Interior', 'slug' => 'dekorasi-desain'],
            ['id' => 26, 'parent_id' => null, 'name' => 'Jasa & Layanan', 'slug' => 'jasa-layanan'],
            ['id' => 27, 'parent_id' => null, 'name' => 'Properti', 'slug' => 'properti'],
            ['id' => 28, 'parent_id' => null, 'name' => 'Pertukangan & Material', 'slug' => 'pertukangan-material'],
            ['id' => 29, 'parent_id' => null, 'name' => 'Mainan & Video Games', 'slug' => 'mainan-video-games'],
            ['id' => 30, 'parent_id' => null, 'name' => 'Komputer & Laptop', 'slug' => 'komputer-laptop'],

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
            ['parent_id' => 1, 'name' => 'Buah & Sayur Segar', 'slug' => 'buah-sayur'],
            ['parent_id' => 1, 'name' => 'Daging & Seafood', 'slug' => 'daging-seafood'],
            ['parent_id' => 1, 'name' => 'Kue Basah & Tradisional', 'slug' => 'kue-basah'],
            ['parent_id' => 1, 'name' => 'Kue Kering', 'slug' => 'kue-kering'],
            ['parent_id' => 1, 'name' => 'Roti & Pastry', 'slug' => 'roti-pastry'],
            ['parent_id' => 1, 'name' => 'Cokelat & Permen', 'slug' => 'cokelat-permen'],
            ['parent_id' => 1, 'name' => 'Beras', 'slug' => 'beras'],
            ['parent_id' => 1, 'name' => 'Minyak Goreng', 'slug' => 'minyak-goreng'],
            ['parent_id' => 1, 'name' => 'Tepung Terigu', 'slug' => 'tepung-terigu'],
            ['parent_id' => 1, 'name' => 'Gula', 'slug' => 'gula'],

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
            ['parent_id' => 2, 'name' => 'Minuman Tradisional (Jamu)', 'slug' => 'minuman-tradisional'],
            ['parent_id' => 2, 'name' => 'Minuman Cokelat', 'slug' => 'minuman-cokelat'],

            // ===================================
            // Subkategori Kuliner & Katering (Parent ID 3)
            // ===================================
            ['parent_id' => 3, 'name' => 'Katering Nasi Kotak', 'slug' => 'katering-nasi-kotak'],
            ['parent_id' => 3, 'name' => 'Katering Tumpeng', 'slug' => 'katering-tumpeng'],
            ['parent_id' => 3, 'name' => 'Katering Harian', 'slug' => 'katering-harian'],
            ['parent_id' => 3, 'name' => 'Katering Diet / Sehat', 'slug' => 'katering-diet'],
            ['parent_id' => 3, 'name' => 'Hampers Makanan', 'slug' => 'hampers-makanan'],
            ['parent_id' => 3, 'name' => 'Snack Box', 'slug' => 'snack-box'],
            ['parent_id' => 3, 'name' => 'Minuman Siap Saji', 'slug' => 'minuman-siap-saji'],

            // ===================================
            // Subkategori Elektronik & Gadget (Parent ID 4)
            // ===================================
            ['parent_id' => 4, 'name' => 'Handphone & Tablet', 'slug' => 'handphone-tablet'],
            ['parent_id' => 4, 'name' => 'Aksesoris Handphone', 'slug' => 'aksesoris-handphone'],
            ['parent_id' => 4, 'name' => 'Smartwatch & Wearable', 'slug' => 'smartwatch-wearable'],
            ['parent_id' => 4, 'name' => 'Kamera Digital', 'slug' => 'kamera-digital'],
            ['parent_id' => 4, 'name' => 'Lensa Kamera', 'slug' => 'lensa-kamera'],
            ['parent_id' => 4, 'name' => 'Aksesoris Kamera', 'slug' => 'aksesoris-kamera'],
            ['parent_id' => 4, 'name' => 'Audio (Headphone & Speaker)', 'slug' => 'audio-speaker'],
            ['parent_id' => 4, 'name' => 'TV & Hiburan Rumah', 'slug' => 'tv-hiburan-rumah'],
            ['parent_id' => 4, 'name' => 'Peralatan Gaming', 'slug' => 'peralatan-gaming'],
            ['parent_id' => 4, 'name' => 'Elektronik Dapur', 'slug' => 'elektronik-dapur'],
            ['parent_id' => 4, 'name' => 'Elektronik Rumah Tangga', 'slug' => 'elektronik-rumah-tangga'],
            ['parent_id' => 4, 'name' => 'Komponen Elektronik', 'slug' => 'komponen-elektronik'],
            ['parent_id' => 4, 'name' => 'Alat Cukur Elektrik', 'slug' => 'alat-cukur-elektrik'],
            ['parent_id' => 4, 'name' => 'Proyektor & Aksesoris', 'slug' => 'proyektor'],

            // ===================================
            // Subkategori Komputer & Laptop (Parent ID 30)
            // ===================================
            ['parent_id' => 30, 'name' => 'Laptop', 'slug' => 'laptop'],
            ['parent_id' => 30, 'name' => 'PC Desktop', 'slug' => 'pc-desktop'],
            ['parent_id' => 30, 'name' => 'Monitor', 'slug' => 'monitor'],
            ['parent_id' => 30, 'name' => 'Printer & Scanner', 'slug' => 'printer-scanner'],
            ['parent_id' => 30, 'name' => 'Penyimpanan Data (Flashdisk, HDD)', 'slug' => 'penyimpanan-data'],
            ['parent_id' => 30, 'name' => 'Aksesoris Komputer (Mouse, Keyboard)', 'slug' => 'aksesoris-komputer'],
            ['parent_id' => 30, 'name' => 'Komponen PC (VGA, RAM, CPU)', 'slug' => 'komponen-pc'],
            ['parent_id' => 30, 'name' => 'Networking (Router, Modem)', 'slug' => 'networking'],
            ['parent_id' => 30, 'name' => 'Software & OS', 'slug' => 'software-os'],

            // ===================================
            // Subkategori Fashion & Pakaian (Parent ID 5)
            // ===================================
            ['id' => 101, 'parent_id' => 5, 'name' => 'Pakaian Pria', 'slug' => 'pakaian-pria'],
            ['id' => 102, 'parent_id' => 5, 'name' => 'Pakaian Wanita', 'slug' => 'pakaian-wanita'],
            ['id' => 103, 'parent_id' => 5, 'name' => 'Sepatu Pria', 'slug' => 'sepatu-pria'],
            ['id' => 104, 'parent_id' => 5, 'name' => 'Sepatu Wanita', 'slug' => 'sepatu-wanita'],
            ['id' => 105, 'parent_id' => 5, 'name' => 'Tas Pria', 'slug' => 'tas-pria'],
            ['id' => 106, 'parent_id' => 5, 'name' => 'Tas Wanita', 'slug' => 'tas-wanita'],
            ['parent_id' => 5, 'name' => 'Jam Tangan Pria', 'slug' => 'jam-tangan-pria'],
            ['parent_id' => 5, 'name' => 'Jam Tangan Wanita', 'slug' => 'jam-tangan-wanita'],
            ['parent_id' => 5, 'name' => 'Aksesoris Pria', 'slug' => 'aksesoris-pria'],
            ['parent_id' => 5, 'name' => 'Aksesoris Wanita', 'slug' => 'aksesoris-wanita'],
            ['parent_id' => 5, 'name' => 'Fashion Anak Laki-Laki', 'slug' => 'fashion-anak-laki'],
            ['parent_id' => 5, 'name' => 'Fashion Anak Perempuan', 'slug' => 'fashion-anak-perempuan'],
            ['id' => 107, 'parent_id' => 5, 'name' => 'Fashion Muslim', 'slug' => 'fashion-muslim'],
            ['parent_id' => 5, 'name' => 'Perhiasan & Logam Mulia', 'slug' => 'perhiasan-logam-mulia'],
            ['parent_id' => 5, 'name' => 'Batik & Tenun', 'slug' => 'batik-tenun'],

            // Sub-subkategori Pakaian Pria (Parent ID 101)
            ['parent_id' => 101, 'name' => 'Kaos Pria', 'slug' => 'kaos-pria'],
            ['parent_id' => 101, 'name' => 'Kemeja Pria', 'slug' => 'kemeja-pria'],
            ['parent_id' => 101, 'name' => 'Celana Panjang Pria', 'slug' => 'celana-panjang-pria'],
            ['parent_id' => 101, 'name' => 'Celana Pendek Pria', 'slug' => 'celana-pendek-pria'],
            ['parent_id' => 101, 'name' => 'Jaket & Mantel Pria', 'slug' => 'jaket-mantel-pria'],
            ['parent_id' => 101, 'name' => 'Pakaian Dalam Pria', 'slug' => 'pakaian-dalam-pria'],
            ['parent_id' => 101, 'name' => 'Baju Tidur Pria', 'slug' => 'baju-tidur-pria'],
            ['parent_id' => 101, 'name' => 'Pakaian Olahraga Pria', 'slug' => 'pakaian-olahraga-pria'],
            ['parent_id' => 101, 'name' => 'Setelan Pria', 'slug' => 'setelan-pria'],

            // Sub-subkategori Pakaian Wanita (Parent ID 102)
            ['parent_id' => 102, 'name' => 'Kaos & Polo Shirt Wanita', 'slug' => 'kaos-polo-wanita'],
            ['parent_id' => 102, 'name' => 'Blouse & Kemeja Wanita', 'slug' => 'blouse-kemeja-wanita'],
            ['parent_id' => 102, 'name' => 'Dress & Gaun', 'slug' => 'dress-gaun'],
            ['parent_id' => 102, 'name' => 'Celana Panjang Wanita', 'slug' => 'celana-panjang-wanita'],
            ['parent_id' => 102, 'name' => 'Celana Pendek Wanita', 'slug' => 'celana-pendek-wanita'],
            ['parent_id' => 102, 'name' => 'Rok Wanita', 'slug' => 'rok-wanita'],
            ['parent_id' => 102, 'name' => 'Jaket, Mantel, & Outerwear', 'slug' => 'outerwear-wanita'],
            ['parent_id' => 102, 'name' => 'Lingerie & Pakaian Dalam', 'slug' => 'pakaian-dalam-wanita'],
            ['parent_id' => 102, 'name' => 'Baju Tidur & Piyama', 'slug' => 'piyama-wanita'],
            ['parent_id' => 102, 'name' => 'Pakaian Hamil', 'slug' => 'pakaian-hamil'],
            ['parent_id' => 102, 'name' => 'Pakaian Olahraga Wanita', 'slug' => 'pakaian-olahraga-wanita'],

            // Sub-subkategori Fashion Muslim (Parent ID 107)
            ['parent_id' => 107, 'name' => 'Hijab & Jilbab', 'slug' => 'hijab-jilbab'],
            ['parent_id' => 107, 'name' => 'Gamis Wanita', 'slug' => 'gamis-wanita'],
            ['parent_id' => 107, 'name' => 'Mukena', 'slug' => 'mukena'],
            ['parent_id' => 107, 'name' => 'Atasan Muslim Wanita', 'slug' => 'atasan-muslim-wanita'],
            ['parent_id' => 107, 'name' => 'Bawahan Muslim Wanita', 'slug' => 'bawahan-muslim-wanita'],
            ['parent_id' => 107, 'name' => 'Baju Koko Pria', 'slug' => 'baju-koko-pria'],
            ['parent_id' => 107, 'name' => 'Sarung', 'slug' => 'sarung'],
            ['parent_id' => 107, 'name' => 'Peci & Kopiah', 'slug' => 'peci-kopiah'],
            ['parent_id' => 107, 'name' => 'Sajada', 'slug' => 'sajada'],
            ['parent_id' => 107, 'name' => 'Baju Renang Muslim', 'slug' => 'baju-renang-muslim'],

            // ===================================
            // Subkategori Kecantikan & Kesehatan (Parent ID 6)
            // ===================================
            ['parent_id' => 6, 'name' => 'Perawatan Wajah (Skincare)', 'slug' => 'perawatan-wajah'],
            ['parent_id' => 6, 'name' => 'Makeup Wajah', 'slug' => 'makeup-wajah'],
            ['parent_id' => 6, 'name' => 'Makeup Mata', 'slug' => 'makeup-mata'],
            ['parent_id' => 6, 'name' => 'Makeup Bibir', 'slug' => 'makeup-bibir'],
            ['parent_id' => 6, 'name' => 'Alat Makeup & Aksesoris', 'slug' => 'alat-makeup'],
            ['parent_id' => 6, 'name' => 'Parfum & Cologne', 'slug' => 'parfum'],
            ['parent_id' => 6, 'name' => 'Perawatan Rambut', 'slug' => 'perawatan-rambut'],
            ['parent_id' => 6, 'name' => 'Perawatan Tubuh (Body Care)', 'slug' => 'perawatan-tubuh'],
            ['parent_id' => 6, 'name' => 'Perawatan Gigi & Mulut', 'slug' => 'perawatan-gigi-mulut'],
            ['parent_id' => 6, 'name' => 'Perawatan Kuku', 'slug' => 'perawatan-kuku'],
            ['parent_id' => 6, 'name' => 'Perawatan Pria (Men\'s Grooming)', 'slug' => 'mens-grooming'],
            ['parent_id' => 6, 'name' => 'Obat & Obat Tradisional', 'slug' => 'obat-obatan'],
            ['parent_id' => 6, 'name' => 'Vitamin & Suplemen', 'slug' => 'vitamin-suplemen'],
            ['parent_id' => 6, 'name' => 'P3K & Alat Medis', 'slug' => 'p3k-alat-medis'],
            ['parent_id' => 6, 'name' => 'Kesehatan Seksual', 'slug' => 'kesehatan-seksual'],
            ['parent_id' => 6, 'name' => 'Masker Kesehatan', 'slug' => 'masker-kesehatan'],

            // ===================================
            // Subkategori Peralatan Rumah Tangga (Parent ID 7)
            // ===================================
            ['parent_id' => 7, 'name' => 'Perlengkapan Dapur', 'slug' => 'perlengkapan-dapur'],
            ['parent_id' => 7, 'name' => 'Perlengkapan Ruang Makan', 'slug' => 'perlengkapan-ruang-makan'],
            ['parent_id' => 7, 'name' => 'Perlengkapan Kamar Tidur', 'slug' => 'perlengkapan-kamar-tidur'],
            ['parent_id' => 7, 'name' => 'Perlengkapan Kamar Mandi', 'slug' => 'perlengkapan-kamar-mandi'],
            ['parent_id' => 7, 'name' => 'Furniture Ruang Tamu', 'slug' => 'furniture-ruang-tamu'],
            ['parent_id' => 7, 'name' => 'Dekorasi & Hiasan Dinding', 'slug' => 'dekorasi'],
            ['parent_id' => 7, 'name' => 'Alat Kebersihan', 'slug' => 'alat-kebersihan'],
            ['parent_id' => 7, 'name' => 'Penyimpanan & Organisasi', 'slug' => 'penyimpanan'],
            ['parent_id' => 7, 'name' => 'Lampu & Pencahayaan', 'slug' => 'lampu-pencahayaan'],
            ['parent_id' => 7, 'name' => 'Perawatan Pakaian & Laundry', 'slug' => 'laundry'],
            ['parent_id' => 7, 'name' => 'Pewangi Ruangan', 'slug' => 'pewangi-ruangan'],

            // ===================================
            // Subkategori Pertukangan & Material (Parent ID 28)
            // ===================================
            ['parent_id' => 28, 'name' => 'Material Bangunan', 'slug' => 'material-bangunan'],
            ['parent_id' => 28, 'name' => 'Perkakas Tangan (Hand Tools)', 'slug' => 'perkakas-tangan'],
            ['parent_id' => 28, 'name' => 'Perkakas Mesin (Power Tools)', 'slug' => 'perkakas-mesin'],
            ['parent_id' => 28, 'name' => 'Perlengkapan Listrik', 'slug' => 'perlengkapan-listrik'],
            ['parent_id' => 28, 'name' => 'Cat & Pelapis', 'slug' => 'cat-pelapis'],
            ['parent_id' => 28, 'name' => 'Ledeng & Pompa Air', 'slug' => 'ledeng-pompa'],
            ['parent_id' => 28, 'name' => 'Kunci & Keamanan', 'slug' => 'kunci-keamanan'],
            ['parent_id' => 28, 'name' => 'Pintu & Jendela', 'slug' => 'pintu-jendela'],

            // ===================================
            // Subkategori Olahraga & Outdoor (Parent ID 8)
            // ===================================
            ['parent_id' => 8, 'name' => 'Sepeda & Aksesoris', 'slug' => 'sepeda'],
            ['parent_id' => 8, 'name' => 'Pakaian Olahraga', 'slug' => 'pakaian-olahraga'],
            ['parent_id' => 8, 'name' => 'Sepatu Olahraga', 'slug' => 'sepatu-olahraga'],
            ['parent_id' => 8, 'name' => 'Alat Fitness & Gym', 'slug' => 'alat-fitness'],
            ['parent_id' => 8, 'name' => 'Perlengkapan Kemah (Camping)', 'slug' => 'camping'],
            ['parent_id' => 8, 'name' => 'Olahraga Air (Renang & Selam)', 'slug' => 'olahraga-air'],
            ['parent_id' => 8, 'name' => 'Olahraga Raket (Badminton, Tenis)', 'slug' => 'olahraga-raket'],
            ['parent_id' => 8, 'name' => 'Olahraga Bola (Sepakbola, Basket)', 'slug' => 'olahraga-bola'],
            ['parent_id' => 8, 'name' => 'Olahraga Bela Diri', 'slug' => 'bela-diri'],
            ['parent_id' => 8, 'name' => 'Skateboard & Sepatu Roda', 'slug' => 'skateboard-sepatu-roda'],
            ['parent_id' => 8, 'name' => 'Peralatan Pancing', 'slug' => 'pancing'],
            ['parent_id' => 8, 'name' => 'Nutrisi Olahraga', 'slug' => 'nutrisi-olahraga'],

            // ===================================
            // Subkategori Otomotif & Kendaraan (Parent ID 9)
            // ===================================
            ['parent_id' => 9, 'name' => 'Mobil Baru', 'slug' => 'mobil-baru'],
            ['parent_id' => 9, 'name' => 'Mobil Bekas', 'slug' => 'mobil-bekas'],
            ['parent_id' => 9, 'name' => 'Sepeda Motor Baru', 'slug' => 'motor-baru'],
            ['parent_id' => 9, 'name' => 'Sepeda Motor Bekas', 'slug' => 'motor-bekas'],
            ['parent_id' => 9, 'name' => 'Suku Cadang Mobil (Sparepart)', 'slug' => 'suku-cadang-mobil'],
            ['parent_id' => 9, 'name' => 'Suku Cadang Motor (Sparepart)', 'slug' => 'suku-cadang-motor'],
            ['parent_id' => 9, 'name' => 'Aksesoris Interior Mobil', 'slug' => 'aksesoris-interior-mobil'],
            ['parent_id' => 9, 'name' => 'Aksesoris Eksterior Mobil', 'slug' => 'aksesoris-eksterior-mobil'],
            ['parent_id' => 9, 'name' => 'Aksesoris Motor', 'slug' => 'aksesoris-motor'],
            ['parent_id' => 9, 'name' => 'Helm Motor', 'slug' => 'helm-motor'],
            ['parent_id' => 9, 'name' => 'Oli & Pelumas Kendaraan', 'slug' => 'oli-pelumas'],
            ['parent_id' => 9, 'name' => 'Perawatan Kendaraan (Car Care)', 'slug' => 'perawatan-kendaraan'],
            ['parent_id' => 9, 'name' => 'Ban Mobil & Motor', 'slug' => 'ban-kendaraan'],
            ['parent_id' => 9, 'name' => 'Audio & Video Mobil', 'slug' => 'audio-video-mobil'],

            // ===================================
            // Subkategori Ibu & Bayi (Parent ID 10)
            // ===================================
            ['parent_id' => 10, 'name' => 'Popok Sekali Pakai', 'slug' => 'popok-sekali-pakai'],
            ['parent_id' => 10, 'name' => 'Pakaian Bayi & Anak', 'slug' => 'pakaian-bayi-anak'],
            ['parent_id' => 10, 'name' => 'Sepatu & Aksesoris Bayi', 'slug' => 'sepatu-aksesoris-bayi'],
            ['parent_id' => 10, 'name' => 'Susu Formula', 'slug' => 'susu-formula'],
            ['parent_id' => 10, 'name' => 'Makanan Bayi & Cemilan', 'slug' => 'makanan-bayi'],
            ['parent_id' => 10, 'name' => 'Botol Susu & Dot', 'slug' => 'botol-susu-dot'],
            ['parent_id' => 10, 'name' => 'Perlengkapan Mandi Bayi', 'slug' => 'mandi-bayi'],
            ['parent_id' => 10, 'name' => 'Perawatan Kulit Bayi', 'slug' => 'perawatan-kulit-bayi'],
            ['parent_id' => 10, 'name' => 'Perlengkapan Ibu Hamil & Menyusui', 'slug' => 'ibu-hamil-menyusui'],
            ['parent_id' => 10, 'name' => 'Stroller, Walker & Car Seat', 'slug' => 'stroller-car-seat'],
            ['parent_id' => 10, 'name' => 'Tas Bayi (Diaper Bag)', 'slug' => 'tas-bayi'],
            ['parent_id' => 10, 'name' => 'Perlengkapan Tidur Bayi', 'slug' => 'tidur-bayi'],
            ['parent_id' => 10, 'name' => 'Mainan Edukasi Bayi', 'slug' => 'mainan-edukasi-bayi'],

            // ===================================
            // Subkategori Mainan & Video Games (Parent ID 29)
            // ===================================
            ['parent_id' => 29, 'name' => 'Konsol Game (PS, Xbox, Nintendo)', 'slug' => 'konsol-game'],
            ['parent_id' => 29, 'name' => 'Kaset / Game Fisik', 'slug' => 'kaset-game-fisik'],
            ['parent_id' => 29, 'name' => 'Aksesoris Konsol Game', 'slug' => 'aksesoris-konsol'],
            ['parent_id' => 29, 'name' => 'Action Figure & Model Kit', 'slug' => 'action-figure'],
            ['parent_id' => 29, 'name' => 'Diecast & Mainan Kendaraan', 'slug' => 'diecast'],
            ['parent_id' => 29, 'name' => 'Mainan Edukasi Anak', 'slug' => 'mainan-edukasi-anak'],
            ['parent_id' => 29, 'name' => 'Puzzle & Board Game', 'slug' => 'puzzle-board-game'],
            ['parent_id' => 29, 'name' => 'Mainan Remote Control (RC)', 'slug' => 'mainan-rc'],
            ['parent_id' => 29, 'name' => 'Boneka & Mainan Plush', 'slug' => 'boneka'],
            ['parent_id' => 29, 'name' => 'Mainan Outdoor & Olahraga', 'slug' => 'mainan-outdoor'],

            // ===================================
            // Subkategori Hobi & Koleksi (Parent ID 11)
            // ===================================
            ['parent_id' => 11, 'name' => 'Alat Musik Kunci (Keyboard, Piano)', 'slug' => 'musik-kunci'],
            ['parent_id' => 11, 'name' => 'Alat Musik Petik (Gitar, Bass)', 'slug' => 'musik-petik'],
            ['parent_id' => 11, 'name' => 'Alat Musik Pukul (Drum, Perkusi)', 'slug' => 'musik-pukul'],
            ['parent_id' => 11, 'name' => 'Aksesoris Musik & Studio', 'slug' => 'aksesoris-musik'],
            ['parent_id' => 11, 'name' => 'Uang Kuno & Perangko', 'slug' => 'uang-kuno-perangko'],
            ['parent_id' => 11, 'name' => 'Koleksi Barang Antik', 'slug' => 'barang-antik'],
            ['parent_id' => 11, 'name' => 'Alat & Bahan Seni Rupa', 'slug' => 'alat-seni-rupa'],
            ['parent_id' => 11, 'name' => 'Kamera Analog & Film', 'slug' => 'kamera-analog'],

            // ===================================
            // Subkategori Buku & Alat Tulis (Parent ID 12)
            // ===================================
            ['parent_id' => 12, 'name' => 'Buku Fiksi & Novel', 'slug' => 'buku-fiksi'],
            ['parent_id' => 12, 'name' => 'Buku Non-Fiksi & Biografi', 'slug' => 'buku-non-fiksi'],
            ['parent_id' => 12, 'name' => 'Buku Pelajaran & Edukasi', 'slug' => 'buku-pelajaran'],
            ['parent_id' => 12, 'name' => 'Komik & Manga', 'slug' => 'komik-manga'],
            ['parent_id' => 12, 'name' => 'Buku Agama & Kepercayaan', 'slug' => 'buku-agama'],
            ['parent_id' => 12, 'name' => 'Buku Anak', 'slug' => 'buku-anak'],
            ['parent_id' => 12, 'name' => 'Alat Tulis & Menggambar', 'slug' => 'alat-tulis'],
            ['parent_id' => 12, 'name' => 'Buku Tulis & Jurnal', 'slug' => 'buku-tulis'],
            ['parent_id' => 12, 'name' => 'Peralatan Sekolah & Kantor', 'slug' => 'peralatan-sekolah-kantor'],
            ['parent_id' => 12, 'name' => 'Kertas & Amplop', 'slug' => 'kertas-amplop'],
            ['parent_id' => 12, 'name' => 'Perlengkapan Melukis', 'slug' => 'perlengkapan-melukis'],

            // ===================================
            // Subkategori Pertanian & Hewan Peliharaan (Parent ID 14)
            // ===================================
            ['parent_id' => 14, 'name' => 'Benih & Bibit Tanaman', 'slug' => 'benih-bibit'],
            ['parent_id' => 14, 'name' => 'Pupuk & Nutrisi Tanaman', 'slug' => 'pupuk'],
            ['parent_id' => 14, 'name' => 'Media Tanam', 'slug' => 'media-tanam'],
            ['parent_id' => 14, 'name' => 'Pot & Perlengkapan Taman', 'slug' => 'pot-taman'],
            ['parent_id' => 14, 'name' => 'Alat Berkebun', 'slug' => 'alat-berkebun'],
            ['parent_id' => 14, 'name' => 'Makanan Kucing', 'slug' => 'makanan-kucing'],
            ['parent_id' => 14, 'name' => 'Makanan Anjing', 'slug' => 'makanan-anjing'],
            ['parent_id' => 14, 'name' => 'Makanan Burung & Ikan', 'slug' => 'makanan-burung-ikan'],
            ['parent_id' => 14, 'name' => 'Aksesoris Hewan Peliharaan', 'slug' => 'aksesoris-hewan'],
            ['parent_id' => 14, 'name' => 'Obat & Vitamin Hewan', 'slug' => 'obat-hewan'],
            ['parent_id' => 14, 'name' => 'Kandang & Tas Hewan', 'slug' => 'kandang-hewan'],

            // ===================================
            // Subkategori Tiket & Voucher (Parent ID 18)
            // ===================================
            ['parent_id' => 18, 'name' => 'Pulsa & Paket Data', 'slug' => 'pulsa-data'],
            ['parent_id' => 18, 'name' => 'Token Listrik PLN', 'slug' => 'token-pln'],
            ['parent_id' => 18, 'name' => 'Voucher Game', 'slug' => 'voucher-game'],
            ['parent_id' => 18, 'name' => 'Tiket Pesawat', 'slug' => 'tiket-pesawat'],
            ['parent_id' => 18, 'name' => 'Tiket Kereta Api', 'slug' => 'tiket-kereta'],
            ['parent_id' => 18, 'name' => 'Voucher Hotel & Penginapan', 'slug' => 'voucher-hotel'],
            ['parent_id' => 18, 'name' => 'Tiket Bioskop & Event', 'slug' => 'tiket-event'],
            ['parent_id' => 18, 'name' => 'Tiket Wisata & Atraksi', 'slug' => 'tiket-wisata'],
            ['parent_id' => 18, 'name' => 'Voucher Belanja & Makanan', 'slug' => 'voucher-belanja-makanan'],
            ['parent_id' => 18, 'name' => 'Pembayaran Tagihan (PDAM, BPJS)', 'slug' => 'tagihan'],

            // ===================================
            // Subkategori Alat Industri & Kantor (Parent ID 17)
            // ===================================
            ['parent_id' => 17, 'name' => 'Mesin Produksi & Industri', 'slug' => 'mesin-produksi'],
            ['parent_id' => 17, 'name' => 'Alat Keselamatan Kerja (K3)', 'slug' => 'keselamatan-kerja'],
            ['parent_id' => 17, 'name' => 'Alat Ukur & Uji', 'slug' => 'alat-ukur'],
            ['parent_id' => 17, 'name' => 'Furniture & Peralatan Kantor', 'slug' => 'furniture-kantor'],
            ['parent_id' => 17, 'name' => 'Seragam Kerja & Safety', 'slug' => 'seragam-kerja'],
            ['parent_id' => 17, 'name' => 'Alat Tulis Kantor (Grosir)', 'slug' => 'atk-grosir'],

            // ===================================
            // Subkategori Bahan Baku & Kemasan (Parent ID 16)
            // ===================================
            ['parent_id' => 16, 'name' => 'Kemasan Plastik & Kertas', 'slug' => 'kemasan-plastik-kertas'],
            ['parent_id' => 16, 'name' => 'Kardus & Kotak Kemasan', 'slug' => 'kardus-kotak'],
            ['parent_id' => 16, 'name' => 'Bubble Wrap & Solasi', 'slug' => 'bubble-wrap-solasi'],
            ['parent_id' => 16, 'name' => 'Bahan Baku Kue & Roti', 'slug' => 'bahan-baku-kue'],
            ['parent_id' => 16, 'name' => 'Bahan Baku Minuman (Bubuk, Sirup)', 'slug' => 'bahan-baku-minuman'],
            ['parent_id' => 16, 'name' => 'Tepung & Biji-bijian', 'slug' => 'tepung-biji-bijian'],
            ['parent_id' => 16, 'name' => 'Bumbu & Rempah Mentah', 'slug' => 'bumbu-rempah-mentah'],

            // ===================================
            // Subkategori Perlengkapan Pesta & Event (Parent ID 15)
            // ===================================
            ['parent_id' => 15, 'name' => 'Balon & Dekorasi Pesta', 'slug' => 'balon-dekorasi'],
            ['parent_id' => 15, 'name' => 'Kostum & Aksesoris Pesta', 'slug' => 'kostum-pesta'],
            ['parent_id' => 15, 'name' => 'Souvenir & Undangan', 'slug' => 'souvenir-undangan'],
            ['parent_id' => 15, 'name' => 'Peralatan Kue & Pesta', 'slug' => 'peralatan-kue-pesta'],
            ['parent_id' => 15, 'name' => 'Lilin & Kembang Api', 'slug' => 'lilin-kembang-api'],

            // ===================================
            // Subkategori Properti (Parent ID 27)
            // ===================================
            ['parent_id' => 27, 'name' => 'Rumah Dijual', 'slug' => 'rumah-dijual'],
            ['parent_id' => 27, 'name' => 'Rumah Disewakan', 'slug' => 'rumah-disewakan'],
            ['parent_id' => 27, 'name' => 'Apartemen Dijual', 'slug' => 'apartemen-dijual'],
            ['parent_id' => 27, 'name' => 'Apartemen Disewakan', 'slug' => 'apartemen-disewakan'],
            ['parent_id' => 27, 'name' => 'Tanah Dijual', 'slug' => 'tanah-dijual'],
            ['parent_id' => 27, 'name' => 'Ruko & Komersial', 'slug' => 'ruko-komersial'],
            ['parent_id' => 27, 'name' => 'Kost & Kontrakan', 'slug' => 'kost-kontrakan'],

            // ===================================
            // Subkategori Jasa & Layanan UMKM Umum (Parent ID 26)
            // ===================================
            ['parent_id' => 26, 'name' => 'Jasa Kurir & Pengiriman', 'slug' => 'jasa-kurir-pengiriman'],
            ['parent_id' => 26, 'name' => 'Jasa Bersih-bersih (Cleaning Service)', 'slug' => 'cleaning-service'],
            ['parent_id' => 26, 'name' => 'Jasa Cuci (Laundry & Dry Clean)', 'slug' => 'laundry'],
            ['parent_id' => 26, 'name' => 'Jasa Pindahan', 'slug' => 'jasa-pindahan'],
            ['parent_id' => 26, 'name' => 'Jasa Cetak & Percetakan', 'slug' => 'percetakan'],
            ['parent_id' => 26, 'name' => 'Jasa Desain Grafis', 'slug' => 'jasa-desain'],
            ['parent_id' => 26, 'name' => 'Jasa Pembuatan Web & Aplikasi', 'slug' => 'jasa-web-aplikasi'],
            ['parent_id' => 26, 'name' => 'Jasa Penerjemah & Penulis', 'slug' => 'penerjemah-penulis'],
            ['parent_id' => 26, 'name' => 'Jasa Service AC & Elektronik', 'slug' => 'service-ac-elektronik'],
            ['parent_id' => 26, 'name' => 'Jasa Service Komputer & HP', 'slug' => 'service-komputer-hp'],
            ['parent_id' => 26, 'name' => 'Jasa Bengkel Kendaraan', 'slug' => 'bengkel-kendaraan'],
            ['parent_id' => 26, 'name' => 'Jasa Fotografi & Videografi', 'slug' => 'fotografi-videografi-layanan'],
            ['parent_id' => 26, 'name' => 'Jasa Wedding & Event Organizer', 'slug' => 'wedding-event-organizer'],
            ['parent_id' => 26, 'name' => 'Jasa Pertukangan & Renovasi', 'slug' => 'pertukangan-renovasi'],
            ['parent_id' => 26, 'name' => 'Jasa Jahit & Permak', 'slug' => 'jahit-permak'],
            ['parent_id' => 26, 'name' => 'Jasa Konsultasi & Bimbingan', 'slug' => 'konsultasi-bimbingan'],
            ['parent_id' => 26, 'name' => 'Jasa Sewa Kendaraan', 'slug' => 'sewa-kendaraan'],
            ['parent_id' => 26, 'name' => 'Jasa Sewa Peralatan', 'slug' => 'sewa-peralatan'],
        ];

        if (Schema::hasTable('categories')) {
            foreach ($categories as $category) {
                // Menggunakan insertOrIgnore untuk menghindari duplikasi jika seeder dijalankan lebih dari sekali pada data yang sama
                DB::table('categories')->insertOrIgnore($category);
            }
        }
    }
}
