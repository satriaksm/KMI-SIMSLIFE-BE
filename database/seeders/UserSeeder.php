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

        $this->command->info('Creating sample customers (30)...');
        $customers = User::factory()->count(30)->create([
            'status' => 'active',
        ]);

        $customerRole = Role::where('name', 'customer')->first();
        if ($customerRole) {
            foreach ($customers as $user) {
                $user->roles()->attach($customerRole->id);
            }
        }

        $this->command->info('Users seeded successfully!');
        $this->command->info('   Admin: 1');
        $this->command->info('   Customers: 30');
    }
}