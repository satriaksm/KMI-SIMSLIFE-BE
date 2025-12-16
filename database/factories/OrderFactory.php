<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Jasa;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'jasa_id' => Jasa::inRandomOrder()->first()?->id ?? Jasa::factory(),
            'nama' => $this->faker->name(),
            'tel' => $this->faker->phoneNumber(),
            'alamat' => $this->faker->address(),
            'catatan' => $this->faker->optional()->sentence(),
            'catatan_alamat' => $this->faker->optional()->sentence(),
            'tanggal' => $this->faker->dateTimeBetween('-30 days', '+7 days')->format('Y-m-d'),
            'waktu' => $this->faker->time('H:i'),
            'metode_pembayaran' => $this->faker->randomElement(['COD', 'QRIS']),
            'promo_code' => $this->faker->optional(0.3)->lexify('PROMO???'),
            'total' => $this->faker->numberBetween(50000, 500000),
            'created_at' => $this->faker->dateTimeBetween('-90 days', 'now'),
        ];
    }

    /**
     * Recent orders (last 7 days)
     */
    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
        ]);
    }

    /**
     * Old orders (more than 30 days)
     */
    public function old(): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => $this->faker->dateTimeBetween('-90 days', '-30 days'),
        ]);
    }
}