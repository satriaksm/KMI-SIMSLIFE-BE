<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Models\Merchant;
use App\Models\Segmentation;
use App\Models\Role;


class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => bcrypt('123123123'),
                'email_verified_at' => now(),
            ]
        );

        // Main user with merchant
        $user = User::firstOrCreate(
            ['email' => 'atallabem@gmail.com'],
            [
                'name' => 'Atta Lab',
                'password' => bcrypt('Sa171278@'),
                'email_verified_at' => now(),
            ]
        );

        // Create segmentation 3 if not exists
        $segmentation = Segmentation::firstOrCreate(
            ['id' => 3],
            ['name' => 'Segmentation 3']
        );

        // Create merchant untuk user with segmentation 3
        Merchant::firstOrCreate(
            ['user_id' => $user->id],
            [
                'name' => 'Atta Lab Store',
                'segmentation_id' => 3,
                'status' => 'approved',
            ]
        );

        // Assign umkm-owner role to user
        $umkmRole = Role::firstOrCreate(['name' => 'umkm-owner']);
        $user->roles()->syncWithoutDetaching([$umkmRole->id]);
    }
}