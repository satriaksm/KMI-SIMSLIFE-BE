<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\CommunityPost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CommunityPost>
 */
class CommunityPostFactory extends Factory
{
    protected $model = CommunityPost::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = $this->faker->sentence(rand(4, 8));
        
        return [
            'user_id' => User::inRandomOrder()->first()->id ?? User::factory(),
            'post_title' => rtrim($title, '.'),
            'post_content' => $this->faker->paragraphs(rand(3, 6), true),
            'post_slug' => Str::slug($title) . '-' . Str::random(5),
            'post_status' => $this->faker->randomElement(['published', 'published', 'published', 'draft', 'archived']),
            'views_count' => $this->faker->numberBetween(0, 1000),
        ];
    }

    /**
     * Indicate that the post is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'post_status' => 'published',
        ]);
    }

    /**
     * Indicate that the post is draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'post_status' => 'draft',
        ]);
    }

    /**
     * Indicate that the post is popular (high views).
     */
    public function popular(): static
    {
        return $this->state(fn (array $attributes) => [
            'views_count' => $this->faker->numberBetween(500, 5000),
        ]);
    }

    /**
     * UMKM related titles
     */
    public function postFactory(): static
    {
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

        $topic = $this->faker->randomElement($topics);

        return $this->state(fn (array $attributes) => [
            'post_title' => $topic['title'],
            'post_content' => $topic['content'],
            'post_slug' => Str::slug($topic['title']) . '-' . Str::random(5),
        ]);
    }
}