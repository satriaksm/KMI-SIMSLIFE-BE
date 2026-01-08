<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;


class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('123123123'),
        ]);
        User::insert([
        [
            'name' => 'Pemilik UMKM',
            'email' => 'umkm@example.com',
            'password' => bcrypt('123123123'),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'coba 1',
            'email' => 'umkm1@example.com',
            'password' => bcrypt('123123123'),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'coba 2',
            'email' => 'umkm2@example.com',
            'password' => bcrypt('123123123'),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'coba 3',
            'email' => 'umkm3@example.com',
            'password' => bcrypt('123123123'),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'coba 4',
            'email' => 'umkm4@example.com',
            'password' => bcrypt('123123123'),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

        
    }
}