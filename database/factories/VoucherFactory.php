<?php
namespace Database\Factories;

use App\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VoucherFactory extends Factory
{
    protected $model = Voucher::class;

    public function definition(): array
    {
        return [
            'merchant_id' => null,
            'event_id' => null,
            'voucher_code' => strtoupper(Str::random(8)),
            'voucher_status' => 'active',
            'voucher_type' => $this->faker->randomElement(['percent', 'fixed']),
            'voucher_description' => $this->faker->sentence(),
            'voucher_start_date' => now()->subDays(rand(0, 10)),
            'voucher_end_date' => now()->addDays(rand(5, 30)),
            'value' => $this->faker->numberBetween(5, 50),
            'max_discount_amount' => $this->faker->randomElement([null, 10000, 20000]),
            'min_purchase_amount' => $this->faker->randomElement([0, 50000, 100000]),
            'usage_limit_per_user' => 1,
            'usage_limit' => $this->faker->randomElement([null, 100, 500]),
        ];
    }
}