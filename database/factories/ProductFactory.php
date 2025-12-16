<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = $this->faker->words(3, true);

        return [
            'merchant_id' => Merchant::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name) . '-' . Str::random(5),
            'description' => $this->faker->paragraph(2),
            'min_purchase' => $this->faker->randomElement([1, 2, 5]),
            'status' => 'published',
        ];
    }

    /**
     * Published products
     */
    public function published(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'published',
        ]);
    }

    /**
     * Draft products
     */
    public function draft(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'draft',
        ]);
    }

    /**
     * Product names for UMKM
     */
    public function productFactory(): static
    {
        $productNames = [
            'Mie Ayam Komplit',
            'Bakso Spesial',
            'Bakmi Jawa',
            'Sate Madura',
            'Rendang Padang',
            'Soto Betawi',
            'Kopi Arabica Premium',
            'Teh Matcha Organik',
            'Sambal Matah Bali',
            'Keripik Singkong',
            'Dodol Garut',
            'Kue Lapis Legit',
            'Bumbu Pecel',
            'Abon Sapi',
            'Kerupuk Udang',
        ];

        $name = $this->faker->randomElement($productNames);

        return $this->state(fn(array $attributes) => [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(5),
        ]);
    }
}
