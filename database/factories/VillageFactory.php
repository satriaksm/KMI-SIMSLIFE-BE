<?php

namespace Database\Factories;

use App\Models\Village;
use App\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

class VillageFactory extends Factory
{
    protected $model = Village::class;

    public function definition()
    {
        return [
            'district_id' => District::factory(),
            'name' => $this->faker->unique()->streetSuffix,
        ];
    }
}
