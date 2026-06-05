<?php

namespace Database\Factories;

use App\Models\District;
use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

class DistrictFactory extends Factory
{
    protected $model = District::class;

    public function definition()
    {
        return [
            'city_id' => City::factory(),
            'name' => $this->faker->unique()->streetName,
        ];
    }
}
