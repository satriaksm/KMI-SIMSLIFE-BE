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

        // Assign role 'admin'
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole && !$systemAdmin->roles->contains($adminRole->id)) {
            $systemAdmin->roles()->attach($adminRole->id);
        }

        $regularAdmin = User::firstOrCreate(
            ['email' => 'admin.regular@simslife.local'],
            [
                'name' => 'Regular Admin',
                'password' => Hash::make('123123123'),
                'status' => 'active',
                'email_verified_at' => now(),
                'is_super_admin' => false, 
            ]
        );

        if ($adminRole && !$regularAdmin->roles->contains($adminRole->id)) {
            $regularAdmin->roles()->attach($adminRole->id);
        }

    }
}
