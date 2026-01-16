<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('123123123'),
            'status' => 'active',
        ]);

        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        // Sample customer dengan nomor telepon untuk pengujian "Gunakan data profil"
        $customer = User::factory()->create([
            'name' => 'Customer Verified',
            'email' => 'customer@example.com',
            'password' => bcrypt('123123123'),
            'status' => 'active',
            'phone' => '081234567890',
        ]);

        $customerRole = Role::where('name', 'customer')->first();
        if ($customerRole) {
            $customer->roles()->syncWithoutDetaching([$customerRole->id]);
        }

        // Sample UMKM jasa dengan nomor telepon
        $umkmJasa = User::factory()->create([
            'name' => 'UMKM Jasa',
            'email' => 'umkmjasa@example.com',
            'password' => bcrypt('123123123'),
            'status' => 'active',
            'phone' => '081298765432',
        ]);

        $umkmRole = Role::where('name', 'umkm-owner')->first();
        if ($umkmRole) {
            $umkmJasa->roles()->syncWithoutDetaching([$umkmRole->id]);
        }


        // $this->command->info('Creating sample customers (30)...');
        // $customers = User::factory()->count(30)->create([
        //     'status' => 'active',
        // ]);

        // // Assign role 'customer' ke semua user (kecuali admin)
        // $customerRole = Role::where('name', 'customer')->first();
        // if ($customerRole) {
        //     // Assign ke semua user yang bukan admin
        //     User::where('id', '!=', $admin->id)->each(function ($user) use ($customerRole) {
        //         $user->roles()->syncWithoutDetaching([$customerRole->id]);
        //     });
        // }

        // $this->command->info('Users seeded successfully!');
        // $this->command->info('   Admin: 1');
        // $this->command->info('   Customers: 30');
    }
}
