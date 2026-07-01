<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\User;
use App\Models\Role;
use App\Models\Address;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MerchantSeeder extends Seeder
{
    public function run(): void
    {
        $merchantsData = [
            [
                'name' => 'Warung Mbok Sri',
                'category' => 'Kuliner',
                'description' => 'Menyediakan berbagai masakan rumahan khas Jawa dengan cita rasa autentik dan harga terjangkau.',
                'email_user' => 'sri@example.com' 
            ],
            [
                'name' => 'Dapur Bu Rini',
                'category' => 'Kuliner',
                'description' => 'Menyajikan lauk rumahan, sambal khas, dan makanan siap santap dengan bahan segar setiap hari.',
                'email_user' => 'rini@example.com'
            ],
            [
                'name' => 'Roti Kampung Banyuanyar',
                'category' => 'Kuliner',
                'description' => 'Menjual roti, donat, bolu, dan kue rumahan tanpa bahan pengawet.',
                'email_user' => 'budi@example.com'
            ],
            [
                'name' => 'Kopi Sudut Kampung',
                'category' => 'Kuliner',
                'description' => 'Kedai kopi lokal dengan kopi nusantara, minuman kekinian, dan camilan.',
                'email_user' => 'rina@example.com'
            ],
            [
                'name' => 'Batik Laras',
                'category' => 'Toko',
                'description' => 'Menjual berbagai produk batik handmade khas Indonesia.',
                'email_user' => 'laras@example.com'
            ],
            [
                'name' => 'Toko Berkah Jaya',
                'category' => 'Toko',
                'description' => 'Menyediakan perlengkapan rumah tangga dan kebutuhan sehari-hari.',
                'email_user' => 'putri@example.com'
            ],
            [
                'name' => 'Craft Nusantara',
                'category' => 'Toko',
                'description' => 'Menjual kerajinan bambu, rotan, dan kayu buatan pengrajin lokal.',
                'email_user' => 'yoga@example.com'
            ],
            [
                'name' => 'Snack Ceria',
                'category' => 'Kuliner',
                'description' => 'Memproduksi berbagai camilan tradisional dan modern.',
                'email_user' => 'fajar@example.com'
            ],
            [
                'name' => 'Fresh Farm',
                'category' => 'Toko',
                'description' => 'Menjual sayur, buah, telur, dan hasil pertanian segar.',
                'email_user' => 'wulan@example.com'
            ],
            [
                'name' => 'Hijab Cantika',
                'category' => 'Toko',
                'description' => 'Menjual hijab, mukena, dan aksesori muslimah dengan desain modern.',
                'email_user' => 'cantika@example.com'
            ],
        ];

        DB::beginTransaction();
        try {
            $umkmRole = Role::where('name', 'umkm-owner')->first();
            $banks = ['BCA', 'BRI', 'MANDIRI'];

            foreach ($merchantsData as $index => $data) {
                $user = User::where('email', $data['email_user'])->first();

                if (!$user) {
                    continue;
                }

                if ($umkmRole) {
                    $user->roles()->syncWithoutDetaching([$umkmRole->id]);
                }
                
                // Cari ID segmentasi berdasarkan nama kategori (contoh: UMKM Kuliner)
                $segmentationName = 'UMKM ' . $data['category'];
                $segmentationId = DB::table('segmentations')->where('name', $segmentationName)->value('id') ?? 1;

                // Daftar variasi jam operasional yang masuk akal
                $scheduleVariations = [
                    ['open' => '06:00', 'close' => '15:00'], // Pagi - Sore
                    ['open' => '07:00', 'close' => '17:00'], // Pagi - Sore
                    ['open' => '08:00', 'close' => '20:00'], // Normal
                    ['open' => '09:00', 'close' => '21:00'], // Normal agak siang
                    ['open' => '10:00', 'close' => '22:00'], // Siang - Malam
                    ['open' => '16:00', 'close' => '23:59'], // Khusus Sore - Malam
                ];
                
                // Pilih satu jadwal secara acak untuk UMKM ini
                $selectedSchedule = $scheduleVariations[array_rand($scheduleVariations)];
                
                // Acak apakah hari minggu libur atau tetap buka
                $isSundayOpen = (bool) rand(0, 1);

                $merchant = Merchant::updateOrCreate(
                    ['slug' => Str::slug($data['name'])],
                    [
                        'user_id' => $user->id,
                        'segmentation_id' => $segmentationId, 
                        'name' => $data['name'],
                        'description' => $data['description'],
                        'logo_path' => null,
                        'cover_path' => null,
                        'phone' => '08' . rand(1111111111, 9999999999),
                        'operational_hours' => [
                            'monday' => ['open' => $selectedSchedule['open'], 'close' => $selectedSchedule['close'], 'is_open' => true],
                            'tuesday' => ['open' => $selectedSchedule['open'], 'close' => $selectedSchedule['close'], 'is_open' => true],
                            'wednesday' => ['open' => $selectedSchedule['open'], 'close' => $selectedSchedule['close'], 'is_open' => true],
                            'thursday' => ['open' => $selectedSchedule['open'], 'close' => $selectedSchedule['close'], 'is_open' => true],
                            'friday' => ['open' => $selectedSchedule['open'], 'close' => $selectedSchedule['close'], 'is_open' => true],
                            'saturday' => ['open' => $selectedSchedule['open'], 'close' => $selectedSchedule['close'], 'is_open' => true],
                            'sunday' => $isSundayOpen 
                                ? ['open' => $selectedSchedule['open'], 'close' => $selectedSchedule['close'], 'is_open' => true] 
                                : ['is_open' => false],
                        ],
                        'status' => 'approved',
                        'bank_code' => $banks[array_rand($banks)],
                        'bank_account_number' => (string)rand(1111111111, 9999999999),
                        'bank_account_name' => $user->name,
                    ]
                );

                if (!Address::where('addressable_type', 'merchant')->where('addressable_id', $merchant->id)->exists()) {
                    Address::create([
                        'addressable_type' => 'merchant',
                        'addressable_id' => $merchant->id,
                        'province_id' => 33,
                        'city_id' => 3372,
                        'district_id' => 337205,
                        'village_id' => 3372051001,
                        'latitude' => -7.55611 + (rand(-100, 100) / 10000),
                        'longitude' => 110.83167 + (rand(-100, 100) / 10000),
                        'detail' => 'Jl. Banyuanyar Raya No. ' . ($index + 1),
                        'label' => 'utama',
                    ]);
                }
            }

            DB::commit();
            $this->command->info('MerchantSeeder berhasil dijalankan!');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Error MerchantSeeder: ' . $e->getMessage());
        }
    }
}