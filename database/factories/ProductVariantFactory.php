<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => strtoupper($this->faker->bothify('SKU-####')),
            'price' => $this->faker->numberBetween(5000, 50000),
            'stock' => $this->faker->numberBetween(1, 50),
        ];
    }

    /**
     * Ensure variant has stock
     */
    public function inStock(): static
    {
        return $this->state(fn() => [
            'stock' => $this->faker->numberBetween(5, 100),
        ]);
    }

    /**
     * Cheap variant (for price filter test)
     */
    public function cheap(): static
    {
        return $this->state(fn() => [
            'price' => $this->faker->numberBetween(1000, 9000),
        ]);
    }

    /**
     * Expensive variant (for price filter test)
     */
    public function expensive(): static
    {
        return $this->state(fn() => [
            'price' => $this->faker->numberBetween(30000, 100000),
        ]);
    }
}
