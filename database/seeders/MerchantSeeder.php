<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Merchant;

class MerchantSeeder extends Seeder
{
    public function run(): void
{
    Merchant::create([
        'user_id' => 2,
        'paguyuban_id' => 1,
        'segmentation_id' => 1,
        'name' => 'Pusat Merchandise Solo (Asli)',
        'slug' =>'/kerajinan' ,
        'description' => 'toko kerajinan',
        'logo_path' => asset('storage/merchant/toko.png'),
        'status' => 'approved',
    ]);

    Merchant::create([
        'user_id' => 3,
        'paguyuban_id' => 1,
        'segmentation_id' => 1,
        'name' => 'Toko Shofia Alat Tulis',
        'slug' =>'/Alat Tulis' ,
        'description' => 'Toko Alat Tulis',
        'logo_path' => asset('storage/merchant/toko.png'),
        'status' => 'approved',
    ]);

    Merchant::create([
        'user_id' => 4,
        'paguyuban_id' => 1,
        'segmentation_id' => 3,
        'name' => 'Bengkel Pardi Bemper Mobil',
        'slug' =>'/bengkel' ,
        'description' => 'Bengkel satset',
        'logo_path' => asset('storage/merchant/jasa.png'),
        'status' => 'approved',
    ]);

    Merchant::create([
        'user_id' => 5,
        'paguyuban_id' => 1,
        'segmentation_id' => 3,
        'name' => 'Nova Laundry Banyuanyar',
        'slug' =>'/laundy' ,
        'description' => 'Laundry harga murah',
        'logo_path' => asset('storage/merchant/jasa.png'),
        'status' => 'approved',
    ]);
    Merchant::create([
        'user_id' => 6,
        'paguyuban_id' => 1,
        'segmentation_id' => 1,
        'name' => 'koprasi merah putih',
        'slug' =>'/bahan baku' ,
        'description' => 'koprasi merah putih',
        'logo_path' => asset('storage/merchant/toko.png'),
        'status' => 'approved',
    ]);
    Merchant::create([
        'user_id' => 6,
        'paguyuban_id' => 1,
        'segmentation_id' => 2,
        'name' => 'Gorengan mba sanuk',
        'slug' =>'/gorengan' ,
        'description' => 'sedia berbagai gorengan',
        'logo_path' => asset('storage/merchant/kuliner.png'),
        'status' => 'approved',
    ]);
}
}