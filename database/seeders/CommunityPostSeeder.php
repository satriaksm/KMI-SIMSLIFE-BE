<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\CommunityPost;
use App\Models\CommunityPostImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CommunityPostSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure storage directory exists
        $this->ensureStorageDirectoryExists();

        // Get all users with customer or merchant role
        $customers = User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['customer', 'merchant']);
        })->get();

        if ($customers->isEmpty()) {
            $this->command->warn('No customers or merchants found. Creating sample users...');
            $customers = User::factory()->count(5)->create();
        }

        // Get admin user 
        $admin = User::whereHas('roles', function ($query) {
            $query->where('name', 'admin');
        })->first();

        $this->command->info('Creating community posts without duplicates...');

        $topics = [
            [
                'title' => 'Cara daftar kur untuk UMKM?',
                'content' => 'Halo bapak/ibu, saya baru mulai jualan, kira-kira kalau mau daftar KUR persyaratannya apa saja ya? Apakah butuh jaminan? Terimakasih.',
            ],
            [
                'title' => 'Trik foto produk makanan dengan HP',
                'content' => 'Cuma mau share, ternyata pakai pencahayaan alami atau ring light murah aja udah cukup banget buat bikin foto produk jualan makin menarik. Hasilnya beda jauh loh! Jangan lupa diedit dikit brightness-nya.',
            ],
            [
                'title' => 'Promo akhir bulan untuk pelanggan setia',
                'content' => 'Tanya dong, strategi promo akhir bulan yang paling efektif buat naikin loyalitas pelanggan itu mending diskon persenan atau beli 1 gratis 1 ya?',
            ],
            [
                'title' => 'Dimana supplier kardus murah di Banyuanyar?',
                'content' => 'Bagi teman-teman UMKM yang kesulitan cari packaging murah, coba cek grosir di dekat alun-alun, mereka lagi diskon besar-besaran. Ada rekomendasi lain nggak?',
            ],
            [
                'title' => 'Berbagi pengalaman pertama jualan online',
                'content' => 'Saya ingin sedikit berbagi cerita tentang bagaimana saya bisa tembus omset 10 juta pertama di bulan ke-3. Intinya konsisten posting, cepat balas chat, dan jangan pelit ramah ke pelanggan.',
            ],
            [
                'title' => 'Rekomendasi jasa kurir yang cepat untuk makanan',
                'content' => 'Buat yang punya usaha kuliner, kalian mending pakai jasa kirim instan ojol biasa atau daftar jadi merchant resminya sih? Share pengalamannya dong, kadang suka bingung soal fee aplikasinya.',
            ],
            [
                'title' => 'Ada yang pernah coba iklan di Instagram?',
                'content' => 'Lagi mikir mau bakar uang dikit buat IG Ads nih. Kira-kira budget 50rb per hari efektif nggak ya buat naikin penjualan makanan ringan? Mohon pencerahannya para suhu.',
            ],
            [
                'title' => 'Bagaimana cara urus izin PIRT?',
                'content' => 'Halo kawan-kawan, saya jualan keripik pisang dan sambal kemasan. Ingin urus PIRT supaya bisa masuk ke minimarket, langkah pertamanya apa saja ya? Apakah ribet?',
            ],
            [
                'title' => 'Pentingnya pencatatan keuangan untuk UMKM',
                'content' => 'Sering kali jualan laris tapi pas dicek kok uangnya nggak kumpul ya? Ternyata kecampur sama uang pribadi. Buat teman-teman, tolong banget biasakan pisahkan rekening usaha dan pribadi!',
            ],
            [
                'title' => 'Rekomendasi kemasan eco-friendly',
                'content' => 'Sekarang banyak pelanggan yang peduli lingkungan. Ada yang tahu vendor kemasan dari bahan cassava (singkong) atau kertas daur ulang yang harganya masih masuk akal?',
            ],
        ];

        foreach ($topics as $index => $topic) {
            $status = 'published';
            if ($index === 8) $status = 'draft';
            if ($index === 9) $status = 'archived';

            $isPopular = ($index < 3);

            CommunityPost::create([
                'user_id' => $customers->random()->id,
                'post_title' => $topic['title'],
                'post_content' => $topic['content'],
                'post_slug' => Str::slug($topic['title']) . '-' . Str::random(5),
                'post_status' => $status,
                'views_count' => $isPopular ? rand(500, 5000) : rand(10, 200),
            ]);
        }



        $totalPosts = CommunityPost::count();
        $totalImages = CommunityPostImage::count();
        
        $this->command->info("Community posts seeded successfully...");
        $this->command->info("   Total Posts: {$totalPosts}");
        $this->command->info("   Total Images: {$totalImages}");
    }

    /**
     * Create images for a post with auto-generated alt text
     */
    private function createImagesForPost(CommunityPost $post, int $count): void
    {
        $postTitleSlug = Str::slug($post->post_title);

        for ($i = 1; $i <= $count; $i++) {
            // Create placeholder image path
            $imagePath = $this->createPlaceholderImage($post->id, $i);

            CommunityPostImage::create([
                'post_id' => $post->id,
                'post_image_path' => $imagePath,
                'alt_text' => "{$postTitleSlug}-{$i}",
            ]);
        }
    }

    /**
     * Create placeholder image using external service
     */
    private function createPlaceholderImage(int $postId, int $index): string
    {
        $directory = "community/posts/{$postId}";
        Storage::disk('public')->makeDirectory($directory);

        $filename = Str::random(20) . '.jpg';
        $fullPath = "{$directory}/{$filename}";

        try {
            // Download image from Lorem Picsum (random image service)
            $imageUrl = "https://picsum.photos/800/600?random={$postId}{$index}";
            $imageContent = file_get_contents($imageUrl);
            
            if ($imageContent !== false) {
                Storage::disk('public')->put($fullPath, $imageContent);
            } else {
                $this->createFallbackImage($fullPath);
            }
        } catch (\Exception $e) {
            $this->createFallbackImage($fullPath);
        }

        return $fullPath;
    }

    private function createFallbackImage(string $path): void
    {
        $placeholderContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        Storage::disk('public')->put($path, $placeholderContent);
    }

    /**
     * Ensure storage directory exists
     */
    private function ensureStorageDirectoryExists(): void
    {
        $publicPath = storage_path('app/public/community/posts');
        
        if (!File::exists($publicPath)) {
            File::makeDirectory($publicPath, 0755, true);
        }
    }
}