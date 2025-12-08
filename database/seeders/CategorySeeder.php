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
            // =============================
            // 1. Makanan & Minuman
            // =============================
            ['parent_id' => null, 'name' => 'Makanan', 'slug' => 'makanan', 'image_path' => 'categories/makanan.png'],
            ['parent_id' => null, 'name' => 'Minuman', 'slug' => 'minuman', 'image_path' => 'categories/minuman.png'],
            ['parent_id' => null, 'name' => 'Elektronik', 'slug' => 'elektronik', 'image_path' => 'categories/elektronik.png'],
            ['parent_id' => null, 'name' => 'Fashion', 'slug' => 'fashion', 'image_path' => 'categories/fashion.png'],
            ['parent_id' => null, 'name' => 'Kecantikan & Kesehatan', 'slug' => 'kecantikan-kesehatan'],
            ['parent_id' => null, 'name' => 'Peralatan Rumah Tangga', 'slug' => 'peralatan-rumah'],
            ['parent_id' => null, 'name' => 'Olahraga', 'slug' => 'olahraga'],
            ['parent_id' => null, 'name' => 'Otomotif', 'slug' => 'otomotif'],
            ['parent_id' => null, 'name' => 'Ibu & Anak', 'slug' => 'ibu-anak'],
            ['parent_id' => null, 'name' => 'Hobi', 'slug' => 'hobi'],
            ['parent_id' => null, 'name' => 'Buku & Alat Tulis', 'slug' => 'buku-atk'],

            // Subkategori Makanan
            ['parent_id' => 1, 'name' => 'Makanan Ringan', 'slug' => 'makanan-ringan'],
            ['parent_id' => 1, 'name' => 'Sembako', 'slug' => 'sembako'],
            ['parent_id' => 1, 'name' => 'Frozen Food', 'slug' => 'frozen-food'],
            ['parent_id' => 1, 'name' => 'Bumbu Masak', 'slug' => 'bumbu-masak'],

            // Subkategori Minuman
            ['parent_id' => 2, 'name' => 'Kopi', 'slug' => 'kopi'],
            ['parent_id' => 2, 'name' => 'Teh', 'slug' => 'teh'],
            ['parent_id' => 2, 'name' => 'Minuman Ringan', 'slug' => 'minuman-ringan'],
            ['parent_id' => 2, 'name' => 'Susu & Olahan', 'slug' => 'susu-olahan'],

            // =============================
            // 2. Elektronik
            // =============================

            // Subkategori Elektronik
            ['parent_id' => 3, 'name' => 'Smartphone', 'slug' => 'smartphone'],
            ['parent_id' => 3, 'name' => 'Laptop', 'slug' => 'laptop'],
            ['parent_id' => 3, 'name' => 'Aksesoris Handphone', 'slug' => 'aksesoris-handphone'],
            ['parent_id' => 3, 'name' => 'Aksesoris Komputer', 'slug' => 'aksesoris-komputer'],
            ['parent_id' => 3, 'name' => 'Televisi', 'slug' => 'televisi'],
            ['parent_id' => 3, 'name' => 'Kamera', 'slug' => 'kamera'],

            // Sub-subkategori Smartphone
            ['parent_id' => null, 'name' => 'Sparepart Smartphone', 'slug' => 'sparepart-smartphone'], // bisa diganti jika punya parent

            // =============================
            // 3. Fashion
            // =============================

            // Subkategori Fashion
            ['parent_id' => 4, 'name' => 'Pakaian Pria', 'slug' => 'pakaian-pria'],
            ['parent_id' => 4, 'name' => 'Pakaian Wanita', 'slug' => 'pakaian-wanita'],
            ['parent_id' => 4, 'name' => 'Sepatu', 'slug' => 'sepatu'],
            ['parent_id' => 4, 'name' => 'Tas', 'slug' => 'tas'],
            ['parent_id' => 4, 'name' => 'Aksesoris Fashion', 'slug' => 'aksesoris-fashion'],

            // Sub-subkategori Pria
            ['parent_id' => null, 'name' => 'Kemeja Pria', 'slug' => 'kemeja-pria'],
            ['parent_id' => null, 'name' => 'Kaos Pria', 'slug' => 'kaos-pria'],

            // =============================
            // 4. Kecantikan & Kesehatan
            // =============================

            ['parent_id' => 5, 'name' => 'Skincare', 'slug' => 'skincare'],
            ['parent_id' => 5, 'name' => 'Makeup', 'slug' => 'makeup'],
            ['parent_id' => 5, 'name' => 'Parfum', 'slug' => 'parfum'],
            ['parent_id' => 5, 'name' => 'Obat & Vitamin', 'slug' => 'obat-vitamin'],
            ['parent_id' => 5, 'name' => 'Perawatan Rambut', 'slug' => 'perawatan-rambut'],

            // =============================
            // 5. Rumah Tangga
            // =============================

            ['parent_id' => 6, 'name' => 'Perabot Rumah', 'slug' => 'perabot-rumah'],
            ['parent_id' => 6, 'name' => 'Alat Dapur', 'slug' => 'alat-dapur'],
            ['parent_id' => 6, 'name' => 'Kamar Tidur', 'slug' => 'kamar-tidur'],
            ['parent_id' => 6, 'name' => 'Kamar Mandi', 'slug' => 'kamar-mandi'],
            ['parent_id' => 6, 'name' => 'Perkakas', 'slug' => 'perkakas'],

            // =============================
            // 6. Olahraga
            // =============================

            ['parent_id' => 7, 'name' => 'Sepeda', 'slug' => 'sepeda'],
            ['parent_id' => 7, 'name' => 'Gym & Fitness', 'slug' => 'gym-fitness'],
            ['parent_id' => 7, 'name' => 'Outdoor', 'slug' => 'outdoor'],

            // =============================
            // 7. Otomotif
            // =============================

            ['parent_id' => 8, 'name' => 'Aksesoris Motor', 'slug' => 'aksesoris-motor'],
            ['parent_id' => 8, 'name' => 'Aksesoris Mobil', 'slug' => 'aksesoris-mobil'],
            ['parent_id' => 8, 'name' => 'Sparepart', 'slug' => 'sparepart'],

            // =============================
            // 8. Ibu & Anak
            // =============================

            ['parent_id' => 9, 'name' => 'Popok Bayi', 'slug' => 'popok-bayi'],
            ['parent_id' => 9, 'name' => 'Pakaian Bayi', 'slug' => 'pakaian-bayi'],
            ['parent_id' => 9, 'name' => 'Mainan Anak', 'slug' => 'mainan-anak'],

            // =============================
            // 9. Hobi
            // =============================

            ['parent_id' => 10, 'name' => 'Musik', 'slug' => 'musik'],
            ['parent_id' => 10, 'name' => 'Koleksi', 'slug' => 'koleksi'],
            ['parent_id' => 10, 'name' => 'Game', 'slug' => 'game'],

            // =============================
            // 10. Buku & ATK
            // =============================

            ['parent_id' => 11, 'name' => 'Novel', 'slug' => 'novel'],
            ['parent_id' => 11, 'name' => 'Komik', 'slug' => 'komik'],
            ['parent_id' => 11, 'name' => 'ATK', 'slug' => 'atk'],

        ];

        foreach ($categories as $category) {
            DB::table('categories')->insert($category);
        }
    }
}