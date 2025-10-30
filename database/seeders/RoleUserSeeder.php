<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Models\Role;


class RoleUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminUser = User::where('name', 'Admin')->first();

        if ($adminUser) {
            $adminRole = Role::where('name', 'admin')->first();
            if ($adminRole) {
                $adminUser->roles()->attach($adminRole);
            }
        }
    }
}
