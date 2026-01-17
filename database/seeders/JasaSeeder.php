<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Jasa;
use App\Models\Merchant;

class JasaSeeder extends Seeder
{
    public function run(): void
    {
        $merchantId = Merchant::inRandomOrder()->value('id');

        Jasa::insert([
            [
                'merchant_id' => $merchantId,
                'title' => 'Service AC',
                'vendor' => 'ArcticFix',
                'price' => 100000,
                'image' => 'https://picsum.photos/seed/ac/400/300',
                'rating' => 4.9,
                'distance_km' => 2.2,
                'duration_hours' => 2,
                'description' => 'Layanan servis dan perawatan AC profesional.',
            ],
            [
                'merchant_id' => $merchantId,
                'title' => 'Les Privat',
                'vendor' => 'TutorKu',
                'price' => 150000,
                'image' => 'https://picsum.photos/seed/les/400/300',
                'rating' => 4.8,
                'distance_km' => 1.9,
                'duration_hours' => 1,
                'description' => 'Les privat berbagai mata pelajaran dengan tutor berpengalaman.',
            ],
            [
                'merchant_id' => $merchantId,
                'title' => 'Laundry',
                'vendor' => 'CuciBersih',
                'price' => 50000,
                'image' => 'https://picsum.photos/seed/laundry/400/300',
                'rating' => 4.7,
                'distance_km' => 3.1,
                'duration_hours' => 24,
                'description' => 'Laundry harian cepat dan rapi.',
            ],
        ]);
    }
}
