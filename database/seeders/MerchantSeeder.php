<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\User;
use App\Models\Role;
use App\Models\Segmentation;
use App\Models\Paguyuban;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MerchantSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating merchants...');

        $operationalHours = [
            'monday' => ['is_open' => true, 'open' => '09:00', 'close' => '20:07'],
            'tuesday' => ['is_open' => true, 'open' => '06:02', 'close' => '22:00'],
            'wednesday' => ['is_open' => true, 'open' => '06:02', 'close' => '23:02'],
            'thursday' => ['is_open' => true, 'open' => '06:00', 'close' => '22:00'],
            'friday' => ['is_open' => false],
            'saturday' => ['is_open' => true, 'open' => '06:01', 'close' => '23:00'],
            'sunday' => ['is_open' => true, 'open' => '06:00', 'close' => '18:00'],
        ];

        // ========================
        // 🔥 AMBIL DATA WILAYAH REAL
        // ========================
        $province = DB::table('provinces')->where('name', 'JAWA TENGAH')->first();
        $city = DB::table('cities')->where('name', 'KOTA SURAKARTA')->first();

        if (!$province || !$city) {
            $this->command->error('❌ Province / City tidak ditemukan. Jalankan MasterDataSeeder dulu.');
            return;
        }

        // ========================
        // 👥 AMBIL USER CUSTOMER
        // ========================
        $customers = User::whereHas('roles', fn($q) => $q->where('name', 'customer'))->get();

        if ($customers->isEmpty()) {
            $this->command->warn('No customers found. Creating sample users...');
            $customers = User::factory()->count(10)->create();

            $customerRole = Role::where('name', 'customer')->first();
            if ($customerRole) {
                foreach ($customers as $user) {
                    $user->roles()->attach($customerRole->id);
                }
            }

            $customers = User::whereHas('roles', fn($q) => $q->where('name', 'customer'))->get();
        }

        if ($customers->isEmpty()) {
            $this->command->warn('Still no customers found. Skip.');
            return;
        }

        $segmentations = Segmentation::all();
        $paguyubans = Paguyuban::where('is_active', true)->get();
        $adminUser = User::whereHas('roles', fn($q) => $q->where('name', 'admin'))->first();

        if ($segmentations->isEmpty()) {
            $this->command->warn('No segmentations found. Skip.');
            return;
        }

        $merchantCount = 0;

        // ========================
        // ✅ APPROVED MERCHANT
        // ========================
        $this->command->info('- Creating approved merchants...');

        foreach ($customers->random(min(20, $customers->count())) as $user) {

            // ambil district & village random dari kota Surakarta
            $district = DB::table('districts')
                ->where('city_id', $city->id)
                ->inRandomOrder()
                ->first();

            $village = DB::table('villages')
                ->where('district_id', $district->id)
                ->inRandomOrder()
                ->first();

            if (!$district || !$village) {
                $this->command->warn('⚠️ District/Village kosong, skip user...');
                continue;
            }

            $merchant = Merchant::factory()
                ->approved()
                ->merchantFactory()
                ->create([
                    'user_id' => $user->id,
                    'segmentation_id' => $segmentations->random()->id,
                    'paguyuban_id' => $paguyubans->isNotEmpty() ? $paguyubans->random()?->id : null,
                    'reviewed_by' => $adminUser?->id,
                    'operational_hours' => $operationalHours,
                ]);

            // alamat
            $merchant->addresses()->create([
                'province_id' => $province->id,
                'city_id' => $city->id,
                'district_id' => $district->id,
                'village_id' => $village->id,
                'detail' => fake()->streetAddress(),
                'label' => 'utama',
                'latitude' => -7.566 + (rand(-100, 100) / 10000),
                'longitude' => 110.82 + (rand(-100, 100) / 10000),
            ]);

            // assign role UMKM
            $umkmRole = Role::where('name', 'umkm-owner')->first();
            if ($umkmRole && !$user->roles()->where('role_id', $umkmRole->id)->exists()) {
                $user->roles()->attach($umkmRole->id);
            }

            $merchantCount++;
        }

        // ========================
        // ⏳ PENDING MERCHANT
        // ========================
        $this->command->info('- Creating pending merchants...');

        foreach ($customers->random(min(5, $customers->count())) as $user) {

            if ($user->merchants()->exists()) continue;

            $district = DB::table('districts')
                ->where('city_id', $city->id)
                ->inRandomOrder()
                ->first();

            $village = DB::table('villages')
                ->where('district_id', $district->id)
                ->inRandomOrder()
                ->first();

            if (!$district || !$village) continue;

            $merchant = Merchant::factory()
                ->pending()
                ->merchantFactory()
                ->create([
                    'user_id' => $user->id,
                    'segmentation_id' => $segmentations->random()->id,
                    'paguyuban_id' => $paguyubans->random()?->id,
                    'operational_hours' => $operationalHours,
                ]);

            $merchant->addresses()->create([
                'province_id' => $province->id,
                'city_id' => $city->id,
                'district_id' => $district->id,
                'village_id' => $village->id,
                'detail' => fake()->streetAddress(),
                'label' => 'utama',
            ]);

            $merchantCount++;
        }

        $this->command->info("✅ Merchants seeded successfully!");
        $this->command->info("Total: {$merchantCount}");
    }
}