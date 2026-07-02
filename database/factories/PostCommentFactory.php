<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\CommunityPost;
use App\Models\PostComment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PostComment>
 */
class PostCommentFactory extends Factory
{
    protected $model = PostComment::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'post_id' => CommunityPost::factory(),
            'user_id' => User::inRandomOrder()->first()->id ?? User::factory(),
            'parent_id' => null, // Top-level comment by default
            'comment_content' => $this->faker->paragraph(rand(1, 3)),
        ];
    }

    /**
     * Indicate that this is a reply to another comment.
     */
    public function reply(?PostComment $parentComment = null): static
    {
        return $this->state(function (array $attributes) use ($parentComment) {
            $parent = $parentComment ?? PostComment::whereNull('parent_id')->inRandomOrder()->first();

            return [
                'parent_id' => $parent?->id,
                'post_id' => $parent?->post_id ?? $attributes['post_id'],
            ];
        });
    }

    /**
     * Short comment.
     */
    public function short(): static
    {
        return $this->state(fn (array $attributes) => [
            'comment_content' => $this->faker->sentence(rand(3, 8)),
        ]);
    }

    /**
     * Long comment.
     */
    public function long(): static
    {
        return $this->state(fn (array $attributes) => [
            'comment_content' => $this->faker->paragraphs(rand(2, 4), true),
        ]);
    }

    public function commentFactory(?string $title = null): static
    {
        return $this->state(function (array $attributes) use ($title) {
            $comments = match($title) {
                'Cara daftar kur untuk UMKM?' => [
                    'Biasanya butuh KTP, KK, Surat Keterangan Usaha dari RT/RW/Kelurahan.',
                    'Gampang kok, datang aja ke bank BRI atau Mandiri terdekat.',
                    'Syaratnya usaha minimal sudah berjalan 6 bulan pak.',
                    'Kalau pinjaman di bawah 50 juta biasanya tanpa jaminan tambahan kok.',
                    'Saya kemarin daftar prosesnya lumayan cepat, sekitar 1 mingguan cair.'
                ],
                'Trik foto produk makanan dengan HP' => [
                    'Wah mantap! Setuju banget, lighting itu kunci.',
                    'Aplikasi edit fotonya pakai apa kak kalau boleh tahu?',
                    'Bener banget, apalagi kalau fotonya di dekat jendela pas pagi hari.',
                    'Coba tambahin properti kecil kayak daun atau kain aesthetic biar makin oke.',
                    'Saya malah cuma pakai kardus bekas dilapisin kertas putih buat studio mini hahaha.'
                ],
                'Promo akhir bulan untuk pelanggan setia' => [
                    'Kalau saran saya sih mending diskon persenan tapi ada minimal belanjanya.',
                    'Buy 1 Get 1 lebih nendang sih menurut pengalaman saya.',
                    'Coba dikasih free ongkir aja, biasanya customer lebih suka bebas ongkir.',
                    'Bisa juga dikasih free gift/tester produk baru kita kak.',
                    'Wah boleh juga idenya.'
                ],
                'Dimana supplier kardus murah di Banyuanyar?' => [
                    'Coba ke Toko ABC di jalan merdeka, situ grosiran lengkap.',
                    'Saya biasanya malah beli online di marketplace oren, cari toko yang satu kota.',
                    'Di deket alun-alun ada toko packaging lumayan murah.',
                    'Kalau butuh kardus pizza ukuran 20x20 saya ada suppliernya, DM aja ya.',
                    'Wah mantap, kebetulan lagi cari supplier yang harganya miring.'
                ],
                'Berbagi pengalaman pertama jualan online' => [
                    'Keren banget! Sangat menginspirasi.',
                    'Konsisten emang paling susah sih, kadang kalau sepi bawaannya males update.',
                    'Wah 10 juta di bulan ketiga? Mantap bener kak, share tips rincinya dong.',
                    'Ramah ke pelanggan itu penting banget, kadang mereka beli karena sellernya ramah.',
                    'Semangat terus usahanya, yang penting telaten.'
                ],
                'Rekomendasi jasa kurir yang cepat untuk makanan' => [
                    'Kalau saya mending daftar merchant resminya, walaupun ada potongannya tapi jangkauan lebih luas.',
                    'Pakai kurir internal sendiri aja kalau jangkauannya masih dekat, lebih aman.',
                    'Tergantung margin makanannya kak, kalau marginnya besar bisa daftar merchant.',
                    'Saya sering pakai kurir ojol biasa kalau pelanggannya mesen via WA.',
                    'Iya kadang potongannya lumayan berasa kalau harganya gak dinaikin.'
                ],
                'Ada yang pernah coba iklan di Instagram?' => [
                    '50rb per hari lumayan efektif kalau target audiensnya di-setting bener (radius lokasi).',
                    'Fotonya harus bener-bener bagus kak kalau mau pasang ads, biar orang berhenti scrolling.',
                    'Coba di-split test aja dulu, beda gambar atau beda caption.',
                    'Saya pernah nyoba 100rb per hari selama 3 hari, balik modal kok.',
                    'Penting tuh masukin link order ke WA biar langsung closing.'
                ],
                'Bagaimana cara urus izin PIRT?' => [
                    'Ke dinas kesehatan setempat kak, nanti ada penyuluhan keamanan pangan dulu.',
                    'Lumayan gampang kok, asalkan denah lokasi produksi jelas dan bersih.',
                    'Sekarang bisa diurus lewat OSS juga loh, coba cek website-nya.',
                    'Waktu itu saya urusnya sekitar 1 bulanan sampai sertifikatnya keluar.',
                    'Penting nih buat nambah trust pembeli.'
                ],
                'Pentingnya pencatatan keuangan untuk UMKM' => [
                    'Setuju banget! Awal-awal saya sering rugi karena uangnya kepakai buat belanja harian dapur.',
                    'Pisahin rekening itu wajib hukumnya. Kalau bisa rekening bank beda.',
                    'Saya pakai aplikasi pencatatan keuangan gratisan di HP, lumayan ngebantu.',
                    'Nah ini nih penyakit UMKM pemula, merasa uang banyak padahal modal belom balik.',
                    'Betul, jangan lupa catat aset peralatan juga buat penyusutan.'
                ],
                'Rekomendasi kemasan eco-friendly' => [
                    'Harganya lumayan lebih mahal sih kak kalau cassava, jadi margin harus dihitung ulang.',
                    'Coba pakai paper bowl aja, sekarang banyak varian ukuran.',
                    'Bisa pakai daun pisang juga untuk dalamnya, lebih wangi dan natural.',
                    'Ada supplier di IG @eco.packaging, harganya lumayan kompetitif.',
                    'Pembeli emang suka sih, sekalian bisa jadi bahan marketing buat nambah nilai jual.'
                ],
                default => [
                    'Wah infonya sangat bermanfaat, terima kasih!',
                    'Semangat terus usahanya, yang penting konsisten.',
                    'Izin share ya infonya, kebetulan teman saya lagi cari ini.',
                    'Terima kasih sharingnya, sangat menginspirasi.',
                    'Mantap kak, sukses selalu untuk usahanya.'
                ],
            };

            return [
                'comment_content' => $this->faker->randomElement($comments),
            ];
        });
    }
}
