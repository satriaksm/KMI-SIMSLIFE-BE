<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Jasa;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating orders...');

        $jasas = Jasa::where('is_active', true)->get();

        if ($jasas->isEmpty()) {
            $this->command->warn('No active jasas found. Run JasaSeeder first.');
            return;
        }

        $totalOrders = 0;

        // Create orders for last 90 days with varying distribution
        $this->command->info('- Creating orders for last 90 days...');

        // Monthly distribution (simulate business growth)
        for ($monthOffset = 2; $monthOffset >= 0; $monthOffset--) {
            $startDate = Carbon::now()->subMonths($monthOffset)->startOfMonth();
            $endDate = Carbon::now()->subMonths($monthOffset)->endOfMonth();

            // More orders in recent months
            $orderCount = match($monthOffset) {
                2 => rand(30, 50),  // 2 months ago
                1 => rand(50, 80),  // 1 month ago
                0 => rand(80, 120), // Current month
            };

            for ($i = 0; $i < $orderCount; $i++) {
                Order::factory()->create([
                    'jasa_id' => $jasas->random()->id,
                    'created_at' => fake()->dateTimeBetween($startDate, $endDate),
                ]);

                $totalOrders++;
            }
        }

        $this->command->info("Orders seeded successfully!");
        $this->command->info("   Total: {$totalOrders}");
    }
}