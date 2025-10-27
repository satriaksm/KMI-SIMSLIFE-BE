<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Role;


class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['admin', 'umkm-owner'] as $name) {
            Role::firstOrCreate(['name' => $name]);
        }
    }
}