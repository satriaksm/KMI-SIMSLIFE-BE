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
        // Super Admin
        $systemAdmin = User::firstOrCreate(
            ['email' => 'admin@sumilir.local'],
            [
                'name' => 'Super  Admin',
                'email' => 'superadmin@example.com',
                'password' => Hash::make('123123123'),
                'status' => 'active',
                'email_verified_at' => now(),
                'is_super_admin' => true, 
            ]
        );

        // Customer
        $customer = User::firstOrCreate(
            ['email' => 'me@example.com'],
            [
                'name' => 'Mee',
                'email' => 'me@example.com',
                'password' => Hash::make('123123123'),
                'status' => 'active',
                'email_verified_at' => now(),
                'is_super_admin' => false, 
            ]
        );

        // Assign role 'admin' to super admin
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole && !$systemAdmin->roles->contains($adminRole->id)) {
            $systemAdmin->roles()->attach($adminRole->id);
        }

        // Assign role 'customer' to customer
        $customerRole = Role::where('name', 'customer')->first();
        if ($customerRole && !$customer->roles->contains($customerRole->id)) {
            $customer->roles()->attach($customerRole->id);
        }

        // (Optional) Regular admin, can be omitted if only one admin needed
        // ...existing code...
    }
}
