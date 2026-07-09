<?php

namespace Database\Seeders;

use App\Models\Paguyuban;
use Illuminate\Database\Seeder;

class PaguyubanSeeder extends Seeder
{
    public function run(): void
    {
        $paguyubans = [
            [
                'name' => 'Paguyuban Kuliner',
                'description' => 'Paguyuban untuk pelaku UMKM kuliner di Banyuanyar',
                'image_path' => 'paguyubans/kuliner.png',
                'contact_info' => '081234567890',
                'is_active' => true,
            ],
            [
                'name' => 'Paguyuban Fashion',
                'description' => 'Komunitas pengusaha fashion dan konveksi Solo Raya',
                'image_path' => 'paguyubans/fashion.png',
                'contact_info' => '081234567891',
                'is_active' => true,
            ],
            [
                'name' => 'Paguyuban Jasa',
                'description' => 'Paguyuban penyedia jasa di Surakarta',
                'image_path' => 'paguyubans/jasa.png',
                'contact_info' => '081234567892',
                'is_active' => true,
            ],
        ];

        foreach ($paguyubans as $paguyuban) {
            Paguyuban::create($paguyuban);
        }

        $this->command->info('Paguyubans seeded successfully!');
    }
}