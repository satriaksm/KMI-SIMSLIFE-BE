<?php

namespace Database\Factories;

use App\Models\Jasa;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\JasaCategory;
use App\Models\JasaSubcategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class JasaFactory extends Factory
{
    protected $model = Jasa::class;

    public function definition()
    {
        // Random: fixed_price atau base_price yang diisi, yang lain 0
        if ($this->faker->boolean) {
            $fixedPrice = $this->faker->numberBetween(10000, 100000);
            $basePrice = 0;
        } else {
            $fixedPrice = 0;
            $basePrice = $this->faker->numberBetween(10000, 100000);
        }

        return [
            'merchant_id' => Merchant::factory(),
            'title' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(10),
            'fixed_price' => $fixedPrice,
            'base_price' => $basePrice,
            'delivery_type' => $this->faker->randomElement(['on-site', 'online', 'in-store']),
            'location_address' => $this->faker->address,
            'service_area' => $this->faker->city,
            'special_notes' => $this->faker->optional()->sentence(),
            'payment_methods' => json_encode(['cod']),
            'operating_days' => json_encode(['senin', 'selasa', 'rabu']),
            'operating_times' => json_encode(['08:00-17:00']),
            'status' => $this->faker->randomElement(['draft', 'active', 'inactive']),
            'is_active' => $this->faker->boolean(90),
        ];
    }

    public function configure()
    {
        return $this->afterCreating(function (Jasa $jasa) {
            $categories = Category::factory()->count(2)->create();
            $jasa->categories()->attach($categories->pluck('id')->toArray());
        });
    }
}
