<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Jasa;

class JasaSeeder extends Seeder
{
    public function run(): void
    {
        Jasa::insert([
            [
                'title' => 'Service Kran Bocor',
                'vendor' => 'ArcticFix',
                'price' => 100000,
                'image' => 'https://picsum.photos/seed/ac/400/300',
                'rating' => 4.9,
                'distance_km' => 2.2,
                'duration_hours' => 2,
                'description' => 'Layanan servis dan perawatan AC profesional.',
            ],
            [
                'title' => 'Tambal Ban',
                'vendor' => 'TutorKu',
                'price' => 150000,
                'image' => 'https://picsum.photos/seed/les/400/300',
                'rating' => 4.8,
                'distance_km' => 1.9,
                'duration_hours' => 1,
                'description' => 'Les privat berbagai mata pelajaran dengan tutor berpengalaman.',
            ],
            [
                'title' => 'Laundry Sepatu',
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
