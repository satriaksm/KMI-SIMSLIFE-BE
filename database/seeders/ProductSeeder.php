<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Merchant;
use App\Models\Category;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\AddonGroup;
use App\Models\AddonGroupOption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🛍️ Creating products...');

        // Create dummy product image if not exists
        if (!Storage::disk('public')->exists('products/dummy-product.jpg')) {
            $img = imagecreatetruecolor(800, 800);
            $bg = imagecolorallocate($img, 240, 240, 240);
            imagefill($img, 0, 0, $bg);
            $textColor = imagecolorallocate($img, 100, 100, 100);
            imagestring($img, 5, 330, 390, 'Dummy Product', $textColor);
            
            Storage::disk('public')->makeDirectory('products');
            
            ob_start();
            imagejpeg($img);
            $imageContent = ob_get_clean();
            imagedestroy($img);
            
            Storage::disk('public')->put('products/dummy-product.jpg', $imageContent);
        }

        $data = array (
  'Warung Mbok Sri' => 
  array (
    0 => 
    array (
      'name' => 'Nasi Gudeg Komplit',
      'price' => 28000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Porsi Biasa',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Porsi Jumbo',
          'price' => 8000,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Telur',
          'price' => 5000,
        ),
        1 => 
        array (
          'name' => 'Ayam Goreng',
          'price' => 10000,
        ),
        2 => 
        array (
          'name' => 'Krecek',
          'price' => 6000,
        ),
        3 => 
        array (
          'name' => 'Es Teh',
          'price' => 5000,
        ),
      ),
    ),
    1 => 
    array (
      'name' => 'Nasi Pecel',
      'price' => 18000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Biasa',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Jumbo',
          'price' => 5000,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Telur',
          'price' => 5000,
        ),
        1 => 
        array (
          'name' => 'Tempe',
          'price' => 3000,
        ),
        2 => 
        array (
          'name' => 'Peyek',
          'price' => 2000,
        ),
      ),
    ),
    2 => 
    array (
      'name' => 'Soto Ayam',
      'price' => 22000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Biasa',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Jumbo',
          'price' => 6000,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Nasi',
          'price' => 5000,
        ),
        1 => 
        array (
          'name' => 'Telur',
          'price' => 5000,
        ),
        2 => 
        array (
          'name' => 'Kerupuk',
          'price' => 2000,
        ),
      ),
    ),
    3 => 
    array (
      'name' => 'Ayam Goreng Kampung',
      'price' => 25000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Dada',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Paha Atas',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Paha Bawah',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Sambal',
          'price' => 3000,
        ),
        1 => 
        array (
          'name' => 'Nasi',
          'price' => 5000,
        ),
      ),
    ),
    4 => 
    array (
      'name' => 'Es Teh Manis',
      'price' => 5000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Normal',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Less Sugar',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
  ),
  'Dapur Bu Rini' => 
  array (
    0 => 
    array (
      'name' => 'Ayam Geprek',
      'price' => 20000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Level 1',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Level 2',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Level 3',
          'price' => 0,
        ),
        3 => 
        array (
          'name' => 'Level 4',
          'price' => 0,
        ),
        4 => 
        array (
          'name' => 'Level 5',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Keju',
          'price' => 5000,
        ),
        1 => 
        array (
          'name' => 'Mozzarella',
          'price' => 8000,
        ),
        2 => 
        array (
          'name' => 'Telur',
          'price' => 5000,
        ),
        3 => 
        array (
          'name' => 'Nasi',
          'price' => 5000,
        ),
      ),
    ),
    1 => 
    array (
      'name' => 'Lele Goreng',
      'price' => 18000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Crispy',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Original',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Sambal',
          'price' => 3000,
        ),
        1 => 
        array (
          'name' => 'Lalapan',
          'price' => 2000,
        ),
      ),
    ),
    2 => 
    array (
      'name' => 'Nasi Campur',
      'price' => 22000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Ayam',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Lele',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Telur Balado',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Kerupuk',
          'price' => 2000,
        ),
        1 => 
        array (
          'name' => 'Es Teh',
          'price' => 5000,
        ),
      ),
    ),
    3 => 
    array (
      'name' => 'Sambal Bawang Botol',
      'price' => 25000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => '100 gr',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => '250 gr',
          'price' => 20000,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
    4 => 
    array (
      'name' => 'Rendang Sapi',
      'price' => 45000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => '250 gr',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => '500 gr',
          'price' => 40000,
        ),
        2 => 
        array (
          'name' => '1 kg',
          'price' => 115000,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
  ),
  'Roti Kampung Banyuanyar' => 
  array (
    0 => 
    array (
      'name' => 'Roti Sobek Coklat',
      'price' => 22000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Coklat',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Keju',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Mix',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Extra Keju',
          'price' => 4000,
        ),
        1 => 
        array (
          'name' => 'Extra Coklat',
          'price' => 4000,
        ),
      ),
    ),
    1 => 
    array (
      'name' => 'Roti Keju',
      'price' => 20000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Original',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Double Cheese',
          'price' => 5000,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Extra Keju',
          'price' => 5000,
        ),
      ),
    ),
    2 => 
    array (
      'name' => 'Donat Kentang',
      'price' => 15000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Coklat',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Strawberry',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Tiramisu',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Box Premium',
          'price' => 5000,
        ),
      ),
    ),
    3 => 
    array (
      'name' => 'Bolu Pisang',
      'price' => 25000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Original',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Choco Banana',
          'price' => 5000,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
    4 => 
    array (
      'name' => 'Brownies Kukus',
      'price' => 30000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Original',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Almond',
          'price' => 8000,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Lilin Ulang Tahun',
          'price' => 5000,
        ),
      ),
    ),
  ),
  'Kopi Sudut Kampung' => 
  array (
    0 => 
    array (
      'name' => 'Espresso',
      'price' => 18000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Single Shot',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Double Shot',
          'price' => 7000,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Extra Shot',
          'price' => 7000,
        ),
      ),
    ),
    1 => 
    array (
      'name' => 'Cappuccino',
      'price' => 24000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Hot',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Ice',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Oat Milk',
          'price' => 8000,
        ),
        1 => 
        array (
          'name' => 'Extra Espresso',
          'price' => 7000,
        ),
        2 => 
        array (
          'name' => 'Whipped Cream',
          'price' => 5000,
        ),
      ),
    ),
    2 => 
    array (
      'name' => 'Latte',
      'price' => 25000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Hot',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Ice',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Vanilla Syrup',
          'price' => 4000,
        ),
        1 => 
        array (
          'name' => 'Hazelnut Syrup',
          'price' => 4000,
        ),
        2 => 
        array (
          'name' => 'Oat Milk',
          'price' => 8000,
        ),
      ),
    ),
    3 => 
    array (
      'name' => 'Es Kopi Susu Gula Aren',
      'price' => 22000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Less Sugar',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Normal',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Extra Sweet',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Extra Espresso',
          'price' => 7000,
        ),
        1 => 
        array (
          'name' => 'Boba',
          'price' => 6000,
        ),
      ),
    ),
    4 => 
    array (
      'name' => 'Matcha Latte',
      'price' => 26000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Hot',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Ice',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Oat Milk',
          'price' => 8000,
        ),
        1 => 
        array (
          'name' => 'Cheese Foam',
          'price' => 7000,
        ),
      ),
    ),
    5 => 
    array (
      'name' => 'Croffle',
      'price' => 18000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Original',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Chocolate',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Caramel',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Ice Cream',
          'price' => 8000,
        ),
        1 => 
        array (
          'name' => 'Keju',
          'price' => 5000,
        ),
      ),
    ),
  ),
  'Batik Laras' => 
  array (
    0 => 
    array (
      'name' => 'Kemeja Batik Pria',
      'price' => 180000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'S',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'M',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'L',
          'price' => 0,
        ),
        3 => 
        array (
          'name' => 'XL',
          'price' => 0,
        ),
        4 => 
        array (
          'name' => 'XXL',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Gift Box',
          'price' => 15000,
        ),
      ),
    ),
    1 => 
    array (
      'name' => 'Blouse Batik Wanita',
      'price' => 170000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'S',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'M',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'L',
          'price' => 0,
        ),
        3 => 
        array (
          'name' => 'XL',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Gift Box',
          'price' => 15000,
        ),
      ),
    ),
    2 => 
    array (
      'name' => 'Kain Batik Tulis',
      'price' => 450000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Motif Parang',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Kawung',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Mega Mendung',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Tas Batik',
          'price' => 65000,
        ),
      ),
    ),
    3 => 
    array (
      'name' => 'Totebag Batik',
      'price' => 65000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Hitam',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Navy',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Cream',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Name Tag Bordir',
          'price' => 10000,
        ),
      ),
    ),
    4 => 
    array (
      'name' => 'Dompet Batik',
      'price' => 55000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Coklat',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Merah',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Biru',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
  ),
  'Toko Berkah Jaya' => 
  array (
    0 => 
    array (
      'name' => 'Ember 20 Liter',
      'price' => 42000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Merah',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Biru',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Hijau',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
    1 => 
    array (
      'name' => 'Sapu Lantai',
      'price' => 35000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Soft Brush',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Hard Brush',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
    2 => 
    array (
      'name' => 'Pel Lantai',
      'price' => 48000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Putih',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Biru',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Refill Kain Pel',
          'price' => 15000,
        ),
      ),
    ),
    3 => 
    array (
      'name' => 'Rak Plastik 3 Susun',
      'price' => 120000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Abu-abu',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Putih',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Jasa Perakitan',
          'price' => 20000,
        ),
      ),
    ),
    4 => 
    array (
      'name' => 'Tempat Sampah',
      'price' => 55000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => '10L',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => '20L',
          'price' => 20000,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
  ),
  'Craft Nusantara' => 
  array (
    0 => 
    array (
      'name' => 'Vas Bambu',
      'price' => 85000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Kecil',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Sedang',
          'price' => 20000,
        ),
        2 => 
        array (
          'name' => 'Besar',
          'price' => 40000,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Gift Wrapping',
          'price' => 10000,
        ),
      ),
    ),
    1 => 
    array (
      'name' => 'Lampu Rotan',
      'price' => 220000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Natural',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Walnut',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Bohlam LED',
          'price' => 25000,
        ),
      ),
    ),
    2 => 
    array (
      'name' => 'Tempat Tisu Kayu',
      'price' => 60000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Jati',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Mahoni',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Ukir Nama',
          'price' => 20000,
        ),
      ),
    ),
    3 => 
    array (
      'name' => 'Keranjang Rotan',
      'price' => 130000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Kecil',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Besar',
          'price' => 40000,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
    4 => 
    array (
      'name' => 'Hiasan Dinding Kayu',
      'price' => 145000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Bulat',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Persegi',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Ukiran Custom',
          'price' => 35000,
        ),
      ),
    ),
  ),
  'Snack Ceria' => 
  array (
    0 => 
    array (
      'name' => 'Keripik Pisang',
      'price' => 18000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Original',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Coklat',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Balado',
          'price' => 0,
        ),
        3 => 
        array (
          'name' => 'Keju',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Ukuran 500 gr',
          'price' => 20000,
        ),
      ),
    ),
    1 => 
    array (
      'name' => 'Keripik Singkong',
      'price' => 16000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Original',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Balado',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'BBQ',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Ukuran 500 gr',
          'price' => 18000,
        ),
      ),
    ),
    2 => 
    array (
      'name' => 'Stik Bawang',
      'price' => 20000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => '250 gr',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => '500 gr',
          'price' => 18000,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
    3 => 
    array (
      'name' => 'Kastengel',
      'price' => 55000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => '250 gr',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => '500 gr',
          'price' => 50000,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Toples Premium',
          'price' => 10000,
        ),
      ),
    ),
    4 => 
    array (
      'name' => 'Nastar',
      'price' => 60000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => '250 gr',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => '500 gr',
          'price' => 55000,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Toples Premium',
          'price' => 10000,
        ),
      ),
    ),
  ),
  'Fresh Farm' => 
  array (
    0 => 
    array (
      'name' => 'Telur Ayam',
      'price' => 30000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => '1 kg',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => '2 kg',
          'price' => 30000,
        ),
        2 => 
        array (
          'name' => '5 kg',
          'price' => 145000,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Kardus',
          'price' => 5000,
        ),
      ),
    ),
    1 => 
    array (
      'name' => 'Wortel',
      'price' => 12000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => '500 gr',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => '1 kg',
          'price' => 10000,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
    2 => 
    array (
      'name' => 'Tomat',
      'price' => 10000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => '500 gr',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => '1 kg',
          'price' => 8000,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
    3 => 
    array (
      'name' => 'Bayam',
      'price' => 6000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => '1 Ikat',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => '3 Ikat',
          'price' => 10000,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
    4 => 
    array (
      'name' => 'Jeruk Manis',
      'price' => 28000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => '1 kg',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => '2 kg',
          'price' => 27000,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
  ),
  'Hijab Cantika' => 
  array (
    0 => 
    array (
      'name' => 'Hijab Pashmina Ceruty',
      'price' => 45000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Hitam',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Cream',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Dusty Pink',
          'price' => 0,
        ),
        3 => 
        array (
          'name' => 'Sage',
          'price' => 0,
        ),
        4 => 
        array (
          'name' => 'Navy',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Gift Box',
          'price' => 15000,
        ),
      ),
    ),
    1 => 
    array (
      'name' => 'Hijab Segi Empat Premium',
      'price' => 55000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Hitam',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Mocca',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Olive',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Gift Box',
          'price' => 15000,
        ),
      ),
    ),
    2 => 
    array (
      'name' => 'Mukena Travel',
      'price' => 145000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Navy',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Pink',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Abu',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
        0 => 
        array (
          'name' => 'Tas Mukena',
          'price' => 25000,
        ),
      ),
    ),
    3 => 
    array (
      'name' => 'Ciput Rajut',
      'price' => 18000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Hitam',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Cream',
          'price' => 0,
        ),
        2 => 
        array (
          'name' => 'Mocca',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
    4 => 
    array (
      'name' => 'Bros Mutiara',
      'price' => 12000,
      'variants' => 
      array (
        0 => 
        array (
          'name' => 'Gold',
          'price' => 0,
        ),
        1 => 
        array (
          'name' => 'Silver',
          'price' => 0,
        ),
      ),
      'addons' => 
      array (
      ),
    ),
  ),
);

        $leafCategories = Category::whereNotNull('parent_id')->get();
        if ($leafCategories->isEmpty()) {
            $this->command->warn('⚠️ No subcategories found. Run CategorySeeder first.');
            return;
        }

        $totalProducts = 0;

        foreach ($data as $merchantName => $products) {
            $merchant = Merchant::where('name', 'like', "%{$merchantName}%")->first();
            if (!$merchant) {
                $this->command->warn("Merchant $merchantName not found! Skipping...");
                continue;
            }

            $this->command->info("  - Creating " . count($products) . " products for {$merchant->name}");

            foreach ($products as $prodData) {
                $product = Product::factory()
                    ->published()
                    ->create([
                        'merchant_id' => $merchant->id,
                        'name' => $prodData['name'],
                        'slug' => Str::slug($merchant->name . ' ' . $prodData['name']),
                        'description' => 'Deskripsi untuk ' . $prodData['name'],
                    ]);

                // Attach category
                $randomCategories = $leafCategories->random(rand(1, min(3, $leafCategories->count())));
                foreach ($randomCategories as $category) {
                    $product->categories()->attach($category->id);
                }

                // Setup variants
                if (!empty($prodData['variants'])) {
                    $option = ProductOption::create([
                        'product_id' => $product->id,
                        'option_name' => 'Varian',
                        'uses_image' => false,
                    ]);

                    foreach ($prodData['variants'] as $idx => $v) {
                        $optVal = ProductOptionValue::create([
                            'product_option_id' => $option->id,
                            'option_value' => $v['name'],
                        ]);

                        $variant = ProductVariant::create([
                            'product_id' => $product->id,
                            'stock' => rand(10, 100),
                            'sku' => 'SKU-' . strtoupper(substr(md5($product->id . $v['name'] . time()), 0, 6)),
                            'price' => $prodData['price'] + $v['price'],
                        ]);

                        $variant->optionValues()->attach($optVal->id);
                    }
                } else {
                    ProductVariant::create([
                        'product_id' => $product->id,
                        'stock' => rand(10, 100),
                        'sku' => 'SKU-' . strtoupper(substr(md5($product->id . time()), 0, 6)),
                        'price' => $prodData['price'],
                    ]);
                }

                // Setup addons
                if (!empty($prodData['addons'])) {
                    $addonGroup = AddonGroup::create([
                        'product_id' => $product->id,
                        'addon_group_name' => 'Tambahan (Opsional)',
                        'selection_type' => 'multiple',
                        'min_selection' => 0,
                        'max_selection' => count($prodData['addons']),
                    ]);

                    foreach ($prodData['addons'] as $a) {
                        $addon = \App\Models\Addon::firstOrCreate([
                            'merchant_id' => $merchant->id,
                            'addon_name' => $a['name'],
                        ]);
                        AddonGroupOption::create([
                            'addon_group_id' => $addonGroup->id,
                            'addon_id' => $addon->id,
                            'addon_price' => $a['price'],
                        ]);
                    }
                }

                // Create product image
                $product->images()->create([
                    'image_path' => "products/dummy-product.jpg",
                    'display_order' => 0,
                    'is_cover' => true,
                ]);

                $totalProducts++;
            }
        }

        $this->command->info(" Products seeded successfully!");
        $this->command->info("    Total products: {$totalProducts}");
    }
}