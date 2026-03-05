<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Rating;
use App\Models\RatingSummary;
use App\Models\Product;
use App\Models\Jasa;
use App\Models\User;
use App\Models\Merchant;

class RatingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get sample data
        $users = User::limit(5)->get();
        $products = Product::whereHas('merchant')->limit(5)->get();
        $jasas = Jasa::whereHas('merchant')->limit(3)->get();

        if ($users->isEmpty()) {
            $this->command->warn('⚠️ Tidak ada user. Jalankan UserSeeder terlebih dahulu.');
            return;
        }

        // ===== RATING UNTUK PRODUCTS =====
        if ($products->isNotEmpty()) {
            foreach ($products as $product) {
                $merchant = $product->merchant;
                
                // Create 3-5 ratings per product
                $ratingCount = rand(3, 5);
                for ($i = 0; $i < $ratingCount; $i++) {
                    $user = $users->random();
                    
                    // Cegah duplicate rating dari user yang sama
                    $ratingExists = Rating::where('user_id', $user->id)
                        ->where('rateable_id', $product->id)
                        ->where('rateable_type', 'App\\Models\\Product')
                        ->exists();

                    if ($ratingExists) {
                        continue;
                    }

                    $rating = Rating::create([
                        'user_id' => $user->id,
                        'merchant_id' => $merchant->id,
                        'rateable_id' => $product->id,
                        'rateable_type' => 'App\\Models\\Product',
                        'rating' => rand(3, 5),
                        'title' => $this->getRandomTitle(),
                        'comment' => $this->getRandomComment(),
                    ]);

                    // Update rating summary
                    RatingSummary::updateFromRating($rating);
                }
            }

            $this->command->info('✅ Rating untuk Products berhasil ditambahkan');
        }

        // ===== RATING UNTUK JASAS =====
        if ($jasas->isNotEmpty()) {
            foreach ($jasas as $jasa) {
                $merchant = $jasa->merchant;
                
                // Create 2-4 ratings per jasa
                $ratingCount = rand(2, 4);
                for ($i = 0; $i < $ratingCount; $i++) {
                    $user = $users->random();
                    
                    // Cegah duplicate rating
                    $ratingExists = Rating::where('user_id', $user->id)
                        ->where('rateable_id', $jasa->id)
                        ->where('rateable_type', 'App\\Models\\Jasa')
                        ->exists();

                    if ($ratingExists) {
                        continue;
                    }

                    $rating = Rating::create([
                        'user_id' => $user->id,
                        'merchant_id' => $merchant->id,
                        'rateable_id' => $jasa->id,
                        'rateable_type' => 'App\\Models\\Jasa',
                        'rating' => rand(3, 5),
                        'title' => $this->getRandomTitle(),
                        'comment' => $this->getRandomComment(),
                    ]);

                    // Update rating summary
                    RatingSummary::updateFromRating($rating);
                }
            }

            $this->command->info('✅ Rating untuk Jasas berhasil ditambahkan');
        }
    }

    private function getRandomTitle(): string
    {
        $titles = [
            'Sangat memuaskan!',
            'Produk berkualitas',
            'Pelayanan terbaik',
            'Recommended!',
            'Worth it',
            'Bagus banget',
            'Sesuai ekspektasi',
            'Cepat dan aman',
            'Puas banget',
            'Mantap!',
        ];

        return $titles[array_rand($titles)];
    }

    private function getRandomComment(): string
    {
        $comments = [
            'Produk sampai dengan aman dan cepat. Packaging rapi. Sangat puas dengan pelayanannya.',
            'Kualitas produk sesuai dengan foto. Harga terjangkau. Akan membeli lagi.',
            'Penjualnya responsif, produk berkualitas. Terima kasih!',
            'Pengiriman cepat, produk bagus. Recommended untuk semua orang.',
            'Sangat puas dengan pembelian ini. Kualitas terbaik sesuai harga.',
            'Pelayanan memuaskan dari awal sampai barang tiba. Terima kasih banyak.',
            'Produk sesuai deskripsi, packaging bagus, pengiriman cepat. 5 bintang!',
            'Rasa/kualitas excellent. Penjual yang bertanggung jawab. Sip!',
            'Tidak mengecewakan. Akan jadi pelanggan setia. Mantap!',
            'Top! Produk bagus, harga wajar, pelayanan ramah. Terima kasih.',
        ];

        return $comments[array_rand($comments)];
    }
}
