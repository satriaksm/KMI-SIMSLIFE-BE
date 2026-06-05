<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Seeder;
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
        // CUSTOMER BASIC
        // =========================
        $customer1 = User::firstOrCreate(
            ['email' => 'me@example.com'],
            [
                'name' => 'Mee',
                'password' => Hash::make('123123123'),
                'status' => 'active',
                'email_verified_at' => now(),
                'is_super_admin' => false,
            ]
        );

<<<<<<< HEAD
        $this->command->info('Creating sample customers (30)...');
        $customers = User::factory()->count(30)->create([
            'status' => 'active',
        ]);

        // Assign role 'customer' ke semua user (kecuali admin)
        $customerRole = Role::where('name', 'customer')->first();
        if ($customerRole) {
            // Assign ke semua user yang bukan admin
            User::where('id', '!=', $admin->id)->each(function ($user) use ($customerRole) {
                $user->roles()->syncWithoutDetaching([$customerRole->id]);
            });
        }

        $this->command->info('Users seeded successfully!');
        $this->command->info('   Admin: 1');
        $this->command->info('   Customers: 30');
=======
        if ($customerRole) {
            $customer1->roles()->syncWithoutDetaching([$customerRole->id]);
        }

        // =========================
        // CUSTOMER VERIFIED (WITH PHONE)
        // =========================
        $customer2 = User::firstOrCreate(
            ['email' => 'customer@example.com'],
            [
                'name' => 'Customer Verified',
                'password' => Hash::make('123123123'),
                'status' => 'active',
                'phone' => '081234567890',
            ]
        );

        if (!$customer2->hasVerifiedEmail()) {
            $customer2->markEmailAsVerified();
        }

        if ($customerRole) {
            $customer2->roles()->syncWithoutDetaching([$customerRole->id]);
        }

        // =========================
        // UMKM OWNER
        // =========================
        $umkmJasa = User::firstOrCreate(
            ['email' => 'umkmjasa@example.com'],
            [
                'name' => 'UMKM Jasa',
                'password' => Hash::make('123123123'),
                'status' => 'active',
                'phone' => '081298765432',
            ]
        );

        if (!$umkmJasa->hasVerifiedEmail()) {
            $umkmJasa->markEmailAsVerified();
        }

        if ($umkmRole) {
            $umkmJasa->roles()->syncWithoutDetaching([$umkmRole->id]);
        }

        // =========================
        // OUTPUT
        // =========================
        $this->command->info(' Test Users seeded successfully!');
        $this->command->info(' Admin: superadmin@example.com (123123123)');
        $this->command->info(' Customer: customer@example.com (123123123)');
        $this->command->info(' UMKM Owner: umkmjasa@example.com (123123123)');
>>>>>>> staging-ta
    }
}