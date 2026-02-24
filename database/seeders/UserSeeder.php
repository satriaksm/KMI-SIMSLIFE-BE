<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => bcrypt('123123123'),
                'status' => 'active',
            ]
        );

        // Verify admin email
        if (!$admin->hasVerifiedEmail()) {
            $admin->markEmailAsVerified();
        }

        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $admin->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        // Sample customer dengan nomor telepon untuk pengujian "Gunakan data profil"
        $customer = User::firstOrCreate(
            ['email' => 'customer@example.com'],
            [
                'name' => 'Customer Verified',
                'password' => bcrypt('123123123'),
                'status' => 'active',
                'phone' => '081234567890',
            ]
        );

        // Verify customer email
        if (!$customer->hasVerifiedEmail()) {
            $customer->markEmailAsVerified();
        }

        $customerRole = Role::where('name', 'customer')->first();
        if ($customerRole) {
            $customer->roles()->syncWithoutDetaching([$customerRole->id]);
        }

        // Sample UMKM jasa dengan nomor telepon
        $umkmJasa = User::firstOrCreate(
            ['email' => 'umkmjasa@example.com'],
            [
                'name' => 'UMKM Jasa',
                'password' => bcrypt('123123123'),
                'status' => 'active',
                'phone' => '081298765432',
            ]
        );

        // Verify UMKM email
        if (!$umkmJasa->hasVerifiedEmail()) {
            $umkmJasa->markEmailAsVerified();
        }

        $umkmRole = Role::where('name', 'umkm-owner')->first();
        if ($umkmRole) {
            $umkmJasa->roles()->syncWithoutDetaching([$umkmRole->id]);
        }

        // Output info
        $this->command->info('✅ Test Users seeded successfully!');
        $this->command->info('   📧 Admin: admin@example.com (password: 123123123)');
        $this->command->info('   📧 Customer: customer@example.com (password: 123123123)');
        $this->command->info('   📧 UMKM Owner: umkmjasa@example.com (password: 123123123)');
        $this->command->info('   All emails verified - Ready to login!');



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
