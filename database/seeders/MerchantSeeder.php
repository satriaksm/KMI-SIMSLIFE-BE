<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\User;
use App\Models\Role;
use App\Models\Segmentation;
use App\Models\Paguyuban;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MerchantSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating merchants...');

        // Get users with customer role
        $customers = User::whereHas('roles', fn($q) => $q->where('name', 'customer'))
            ->get();

        if ($customers->isEmpty()) {
            $this->command->warn('No customers found. Creating sample users...');
            $customers = User::factory()->count(10)->create();
            $customerRole = Role::where('name', 'customer')->first();
            if ($customerRole) {
                foreach ($customers as $user) {
                    $user->roles()->attach($customerRole->id);
                }
            }
            // Refresh $customers collection after attach
            $customers = User::whereHas('roles', fn($q) => $q->where('name', 'customer'))->get();
        }

        if ($customers->isEmpty()) {
            $this->command->warn('Still no customers found after creation. Skipping merchant seeding.');
            return;
        }
        $segmentations = Segmentation::all();
        $paguyubans = Paguyuban::where('is_active', true)->get();
        $adminUser = User::whereHas('roles', fn($q) => $q->where('name', 'admin'))->first();

        $merchantCount = 0;

        // Create approved merchants (1-3 per user randomly)
        $this->command->info('- Creating approved merchants (1-3 per user)...');
        foreach ($customers->random(min(20, $customers->count())) as $user) {
            $merchantTotal = rand(1, 3);
            for ($m = 0; $m < $merchantTotal; $m++) {
                $merchant = Merchant::factory()
                    ->approved()
                    ->merchantFactory()
                    ->create([
                        'user_id' => $user->id,
                        'segmentation_id' => $segmentations->random()->id,
                        'paguyuban_id' => $paguyubans->random()?->id,
                        'reviewed_by' => $adminUser?->id,
                    ]);

                // Create address for merchant
                $merchant->addresses()->create([
                    'province_id' => 1, // Jawa Tengah
                    'city_id' => 1, // Surakarta
                    'district_id' => 1, // Banjarsari
                    'village_id' => 1, // Banyuanyar
                    'detail' => fake()->streetAddress(),
                    'label' => 'utama',
                    'latitude' => -7.5568 + (rand(-100, 100) / 10000),
                    'longitude' => 110.8282 + (rand(-100, 100) / 10000),
                ]);

                // Add umkm-owner role to user
                $umkmRole = Role::where('name', 'umkm-owner')->first();
                if ($umkmRole && !$user->roles()->where('role_id', $umkmRole->id)->exists()) {
                    $user->roles()->attach($umkmRole->id);
                }

                $merchantCount++;
            }
        }

        // Create pending merchants
        $this->command->info('- Creating pending merchants (5)...');
        foreach ($customers->random(min(5, $customers->count())) as $user) {
            // Skip if user already has merchant
            if ($user->merchants()->exists()) continue;

            $merchant = Merchant::factory()
                ->pending()
                ->merchantFactory()
                ->create([
                    'user_id' => $user->id,
                    'segmentation_id' => $segmentations->random()->id,
                    'paguyuban_id' => $paguyubans->random()?->id,
                ]);

            $merchant->addresses()->create([
                'province_id' => 1,
                'city_id' => 1,
                'district_id' => 1,
                'village_id' => 1,
                'detail' => fake()->streetAddress(),
                'label' => 'utama',
            ]);

            $merchantCount++;
        }

        $this->command->info("Merchants seeded successfully!");
        $this->command->info("   Total: {$merchantCount}");
    }
}