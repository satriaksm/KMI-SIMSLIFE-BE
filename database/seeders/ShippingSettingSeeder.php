<?php

namespace Database\Seeders;

use App\Models\ShippingSetting;
use Illuminate\Database\Seeder;

class ShippingSettingSeeder extends Seeder
{
    public function run(): void
    {
        ShippingSetting::query()->firstOrCreate(
            ['status' => 'active'],
            [
                'base_cost' => 5000,
                'cost_per_km' => 2000,
            ]
        );
    }
}