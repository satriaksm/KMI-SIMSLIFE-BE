<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Jasa;
use App\Models\Merchant;
use App\Models\Category;
use Illuminate\Support\Str;

class JasaSeeder extends Seeder
{
    public function run(): void
    {
        $categories = Category::whereNotNull('parent_id')->pluck('id')->toArray();
        $getRandomCategory = function() use ($categories) {
            return !empty($categories) ? $categories[array_rand($categories)] : null;
        };

        // Data spesifik untuk masing-masing Merchant
        $jasaData = [
            'Bengkel Motor Bayu' => [
                [
                    'title' => 'Ganti Oli Mesin & Gardan',
                    'description' => 'Layanan ganti oli mesin dan gardan langsung di bengkel. Termasuk pengecekan ringan.',
                    'service_type_booking' => 'keranjang',
                    'delivery_type' => 'in-store',
                    'fixed_price' => 65000,
                    'base_price' => 0,
                ],
                [
                    'title' => 'Servis Rutin / Tune Up',
                    'description' => 'Servis lengkap motor, bersihkan karburator/injektor, cek busi dan rem. Bebas antre jika booking.',
                    'service_type_booking' => 'booking',
                    'delivery_type' => 'in-store',
                    'fixed_price' => 120000,
                    'base_price' => 0,
                ],
                [
                    'title' => 'Konsultasi Kerusakan Mesin',
                    'description' => 'Tanya jawab mengenai suara kasar pada mesin, kendala kelistrikan, dan estimasi biaya perbaikan.',
                    'service_type_booking' => 'konsultasi',
                    'delivery_type' => 'online',
                    'fixed_price' => 0,
                    'base_price' => 10000,
                ],
                [
                    'title' => 'Panggilan Bengkel Darurat',
                    'description' => 'Layanan darurat teknisi datang ke lokasi Anda jika motor mogok atau ban bocor di jalan.',
                    'service_type_booking' => 'booking',
                    'delivery_type' => 'on-site',
                    'fixed_price' => 50000, // Biaya panggil, sparepart terpisah
                    'base_price' => 0,
                ],
                [
                    'title' => 'Ganti Ban Luar',
                    'description' => 'Pemasangan ban luar baru untuk berbagai jenis ukuran ban motor standar.',
                    'service_type_booking' => 'keranjang',
                    'delivery_type' => 'in-store',
                    'fixed_price' => 150000,
                    'base_price' => 0,
                ],
            ],
            'Intan Laundry' => [
                [
                    'title' => 'Cuci Kiloan Reguler (Bawa ke Tempat)',
                    'description' => 'Layanan cuci pakaian kiloan selesai dalam 2-3 hari. Harum, bersih, rapi.',
                    'service_type_booking' => 'keranjang',
                    'delivery_type' => 'in-store',
                    'fixed_price' => 6000,
                    'base_price' => 0,
                ],
                [
                    'title' => 'Antar Jemput Laundry Kiloan',
                    'description' => 'Kurir kami akan datang menjemput pakaian kotor Anda di rumah.',
                    'service_type_booking' => 'booking',
                    'delivery_type' => 'on-site',
                    'fixed_price' => 15000, // Minimal penjemputan
                    'base_price' => 0,
                ],
                [
                    'title' => 'Cuci Sepatu Premium',
                    'description' => 'Layanan cuci sepatu (sneakers, canvas, suede) menggunakan sabun khusus.',
                    'service_type_booking' => 'keranjang',
                    'delivery_type' => 'in-store',
                    'fixed_price' => 35000,
                    'base_price' => 0,
                ],
                [
                    'title' => 'Cuci Karpet/Ambal Besar (Ambil di Rumah)',
                    'description' => 'Pesan jadwal untuk pengambilan karpet besar yang akan dicuci.',
                    'service_type_booking' => 'booking',
                    'delivery_type' => 'on-site',
                    'fixed_price' => 100000,
                    'base_price' => 0,
                ],
                [
                    'title' => 'Konsultasi Perawatan Noda Pakaian',
                    'description' => 'Konsultasi apakah noda luntur, tinta, atau getah pada pakaian kesayangan Anda bisa dihilangkan.',
                    'service_type_booking' => 'konsultasi',
                    'delivery_type' => 'online',
                    'fixed_price' => 0,
                    'base_price' => 5000,
                ],
            ],
            'Rizki Elektronik Service' => [
                [
                    'title' => 'Servis AC Panggilan',
                    'description' => 'Layanan pembersihan dan servis AC, cuci filter, dan pengecekan freon.',
                    'service_type_booking' => 'booking',
                    'delivery_type' => 'on-site',
                    'fixed_price' => 75000,
                    'base_price' => 0,
                ],
                [
                    'title' => 'Pengecekan TV / Kulkas Rusak di Rumah',
                    'description' => 'Pengecekan awal kendala TV mati total, tidak ada gambar, atau kulkas tidak dingin.',
                    'service_type_booking' => 'konsultasi',
                    'delivery_type' => 'on-site',
                    'fixed_price' => 0,
                    'base_price' => 50000, // Biaya cek ke rumah
                ],
                [
                    'title' => 'Perbaikan Kipas Angin (Bawa Langsung)',
                    'description' => 'Servis kipas angin mati, putaran pelan, atau berdengung.',
                    'service_type_booking' => 'keranjang',
                    'delivery_type' => 'in-store',
                    'fixed_price' => 45000,
                    'base_price' => 0,
                ],
                [
                    'title' => 'Servis Mesin Cuci Panggilan',
                    'description' => 'Penanganan masalah mesin cuci error, tidak bisa berputar, atau air tidak keluar.',
                    'service_type_booking' => 'booking',
                    'delivery_type' => 'on-site',
                    'fixed_price' => 100000, // Ongkos kerja dasar
                    'base_price' => 0,
                ],
                [
                    'title' => 'Konsultasi Beli Komponen Elektronik',
                    'description' => 'Bagi yang ingin memperbaiki sendiri, tanyakan komponen apa yang rusak dan harganya kepada kami.',
                    'service_type_booking' => 'konsultasi',
                    'delivery_type' => 'online',
                    'fixed_price' => 0,
                    'base_price' => 15000,
                ],
            ],
            'Nanda Barber Shop' => [
                [
                    'title' => 'Potong Rambut Pria Dewasa',
                    'description' => 'Potong rambut rapi model terbaru. Datang, ambil antrean, dan langsung dilayani.',
                    'service_type_booking' => 'keranjang',
                    'delivery_type' => 'in-store',
                    'fixed_price' => 20000,
                    'base_price' => 0,
                ],
                [
                    'title' => 'Booking Jadwal Potong Rambut',
                    'description' => 'Tidak ingin mengantre? Pesan jadwal potong rambut pada jam yang Anda tentukan.',
                    'service_type_booking' => 'booking',
                    'delivery_type' => 'in-store',
                    'fixed_price' => 25000,
                    'base_price' => 0,
                ],
                [
                    'title' => 'Home Service Barbershop',
                    'description' => 'Potong rambut di kenyamanan rumah Anda sendiri. Kapster kami yang akan datang.',
                    'service_type_booking' => 'booking',
                    'delivery_type' => 'on-site',
                    'fixed_price' => 60000,
                    'base_price' => 0,
                ],
                [
                    'title' => 'Konsultasi Gaya Rambut',
                    'description' => 'Bingung potongan apa yang cocok dengan bentuk wajah Anda? Diskusikan dulu bersama ahli kami.',
                    'service_type_booking' => 'konsultasi',
                    'delivery_type' => 'online',
                    'fixed_price' => 0,
                    'base_price' => 10000,
                ],
                [
                    'title' => 'Paket Potong Rambut + Creambath',
                    'description' => 'Cukur rapi dilanjutkan perawatan creambath dan pijat relaksasi kepala ringan.',
                    'service_type_booking' => 'keranjang',
                    'delivery_type' => 'in-store',
                    'fixed_price' => 50000,
                    'base_price' => 0,
                ],
            ],
            'Ayu Tailor' => [
                [
                    'title' => 'Permak Celana / Baju',
                    'description' => 'Potong celana kepanjangan, kecilkan pinggang, pasang resleting. Bisa langsung dibawa ke lokasi.',
                    'service_type_booking' => 'keranjang',
                    'delivery_type' => 'in-store',
                    'fixed_price' => 15000,
                    'base_price' => 0,
                ],
                [
                    'title' => 'Jahit Baju Seragam / Pesta',
                    'description' => 'Booking jadwal untuk pengukuran badan di toko kami agar tidak bentrok dengan pelanggan lain.',
                    'service_type_booking' => 'booking',
                    'delivery_type' => 'in-store',
                    'fixed_price' => 150000,
                    'base_price' => 0,
                ],
                [
                    'title' => 'Ukur Baju Panggilan (Untuk Keluarga)',
                    'description' => 'Ingin buat seragam keluarga tapi malas keluar? Kami akan datang ke rumah untuk mengukur.',
                    'service_type_booking' => 'booking',
                    'delivery_type' => 'on-site',
                    'fixed_price' => 100000,
                    'base_price' => 0,
                ],
                [
                    'title' => 'Konsultasi Desain Gaun / Kebaya',
                    'description' => 'Kirimkan referensi desain pakaian Anda, dan kami akan beri saran material kain serta estimasi biaya.',
                    'service_type_booking' => 'konsultasi',
                    'delivery_type' => 'online',
                    'fixed_price' => 0,
                    'base_price' => 20000,
                ],
                [
                    'title' => 'Jahit Sarung Bantal / Sprei',
                    'description' => 'Jahit kebutuhan linen rumah tangga ukuran standar.',
                    'service_type_booking' => 'keranjang',
                    'delivery_type' => 'in-store',
                    'fixed_price' => 35000,
                    'base_price' => 0,
                ],
            ]
        ];

        // Ambil semua merchant jasa yang ada di array
        $merchants = Merchant::whereIn('name', array_keys($jasaData))->get();

        foreach ($merchants as $merchant) {
            $services = $jasaData[$merchant->name] ?? [];

            foreach ($services as $service) {
                $isBooking = $service['service_type_booking'] === 'booking';

                $jasa = Jasa::create([
                    'merchant_id' => $merchant->id,
                    'title' => $service['title'],
                    'slug' => Str::slug($service['title'] . '-' . uniqid()),
                    'fixed_price' => $service['fixed_price'],
                    'base_price' => $service['base_price'],
                    'description' => $service['description'],
                    'delivery_type' => $service['delivery_type'],
                    'service_type_booking' => $service['service_type_booking'],
                    'status' => 'published',
                    'location_address' => ($service['delivery_type'] === 'in-store' || $service['delivery_type'] === 'on-site') 
                        ? 'Alamat toko ' . $merchant->name . ' atau lokasi pelanggan' : null,
                    'special_notes' => 'Harap perhatikan detail layanan sebelum memesan.',
                    'payment_methods' => 'cod,transfer',
                    'operating_days' => '1,2,3,4,5,6',
                    'operating_times' => $isBooking ? '08:00,09:00,10:00,13:00,14:00,15:00' : null,
                ]);

                // Assign random category if available
                $catId = $getRandomCategory();
                if ($catId) {
                    $jasa->categories()->attach($catId);
                }
            }
        }
    }
}
