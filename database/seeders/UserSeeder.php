<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use App\Models\Address;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // =========================
        // GET ROLES (AMBIL SEKALI)
        // =========================
        $adminRole = Role::where('name', 'admin')->first();
        $customerRole = Role::where('name', 'customer')->first();
        $umkmRole = Role::where('name', 'umkm-owner')->first();

        // =========================
        // SUPER ADMIN
        // =========================
        $systemAdmin = User::firstOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('123123123'),
                'status' => 'active',
                'email_verified_at' => now(),
                'is_super_admin' => true,
            ]
        );

        if ($adminRole) {
            $systemAdmin->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        // =========================
        // SEED USERS DARI DATA MENTAH
        // =========================
        $usersData = [
            ['name' => 'Ahmad Fauzan', 'email' => 'ahmad@example.com'],
            ['name' => 'Siti Nurhaliza', 'email' => 'siti@example.com'],
            ['name' => 'Budi Santoso', 'email' => 'budi@example.com'],
            ['name' => 'Rina Lestari', 'email' => 'rina@example.com'],
            ['name' => 'Dimas Prakoso', 'email' => 'dimas@example.com'],
            ['name' => 'Putri Ayuningtyas', 'email' => 'putri@example.com'],
            ['name' => 'Yoga Pratama', 'email' => 'yoga@example.com'],
            ['name' => 'Fajar Nugroho', 'email' => 'fajar@example.com'],
            ['name' => 'Wulan Safitri', 'email' => 'wulan@example.com'],
            ['name' => 'Bayu Kurniawan', 'email' => 'bayu@example.com'],
            ['name' => 'Intan Permata', 'email' => 'intan@example.com'],
            ['name' => 'Rizki Ramadhan', 'email' => 'rizki@example.com'],
            ['name' => 'Nanda Saputra', 'email' => 'nanda@example.com'],
            ['name' => 'Ayu Maharani', 'email' => 'ayu@example.com'],
            ['name' => 'Dwi Hartono', 'email' => 'dwi@example.com'],
            ['name' => 'Eka Prasetyo', 'email' => 'eka@example.com'],
            ['name' => 'Tika Anggraini', 'email' => 'tika@example.com'],
            ['name' => 'Reza Firmansyah', 'email' => 'reza@example.com'],
            ['name' => 'Cindy Olivia', 'email' => 'cindy@example.com'],
            ['name' => 'Hendra Wijaya', 'email' => 'hendra@example.com'],
            ['name' => 'Mbok Sri', 'email' => 'sri@example.com'],
            ['name' => 'Bu Rini', 'email' => 'rini@example.com'],
            ['name' => 'Laras', 'email' => 'laras@example.com'],
            ['name' => 'Cantika', 'email' => 'cantika@example.com'],
        ];

        // =========================
        // PASTIKAN DATA WILAYAH DUMMY ADA
        // =========================
        DB::table('provinces')->insertOrIgnore(['id' => 33, 'name' => 'Jawa Tengah']);
        DB::table('cities')->insertOrIgnore(['id' => 3372, 'province_id' => 33, 'name' => 'Surakarta']);
        DB::table('districts')->insertOrIgnore(['id' => 337205, 'city_id' => 3372, 'name' => 'Banjarsari']);
        DB::table('villages')->insertOrIgnore(['id' => 3372051001, 'district_id' => 337205, 'name' => 'Banyuanyar']);

        DB::beginTransaction();
        try {
            foreach ($usersData as $index => $data) {
                $user = User::firstOrCreate(
                    ['email' => $data['email']],
                    [
                        'name' => $data['name'],
                        'phone' => '08' . rand(1111111111, 9999999999),
                        'password' => Hash::make('P@ssword123'),
                        'status' => 'active',
                        'email_verified_at' => now(),
                        'profile_picture_path' => null,
                        'is_super_admin' => false,
                    ]
                );

                if ($customerRole) {
                    $user->roles()->syncWithoutDetaching([$customerRole->id]);
                }

                // Cek apakah address sudah ada agar tidak dobel saat seeder di-run ulang
                if (!Address::where('addressable_type', 'user')->where('addressable_id', $user->id)->exists()) {
                    Address::create([
                        'addressable_type' => 'user',
                        'addressable_id' => $user->id,
                        'province_id' => 33, // Dummy Jawa Tengah
                        'city_id' => 3372, // Dummy Surakarta
                        'district_id' => 337205, // Dummy Banjarsari
                        'village_id' => 3372051001, // Dummy Kelurahan
                        'latitude' => -7.55611 + (rand(-100, 100) / 10000),
                        'longitude' => 110.83167 + (rand(-100, 100) / 10000),
                        'detail' => 'Jl. Banjarsari Raya No. ' . ($index + 1),
                        'label' => 'utama',
                    ]);
                }
            }

            DB::commit();
            $this->command->info('Users seeded successfully!');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('Error UserSeeder: ' . $e->getMessage());
        }
    }
}