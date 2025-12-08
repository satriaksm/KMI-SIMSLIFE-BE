<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Promo;

class PromoSeeder extends Seeder
{
    public function run(): void
    {
        Promo::insert([
            [
                'code' => 'PROMO5',
                'title' => 'Diskon 5%',
                'desc' => 'Potongan 5% untuk semua jasa.',
                'type' => 'percent',
                'value' => 5,
                'is_active' => true,
            ],
            [
                'code' => 'HEMAT10',
                'title' => 'Diskon Rp10.000',
                'desc' => 'Potongan langsung Rp10.000 untuk servis AC.',
                'type' => 'flat',
                'value' => 10000,
                'is_active' => true,
            ],
        ]);
    }
}
