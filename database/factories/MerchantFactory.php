<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\User;
use App\Models\Segmentation;
use App\Models\Paguyuban;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    public function definition(): array
    {
        $name = $this->faker->company();
        
        return [
            'user_id' => User::factory(),
            'paguyuban_id' => null,
            'segmentation_id' => Segmentation::inRandomOrder()->first()?->id ?? 1,
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(5),
            'description' => $this->faker->paragraph(),
            'logo_path' => null,
            'phone' => $this->faker->phoneNumber(),
            'status' => 'approved',
            'rejection_reason' => null,
            'reviewed_by' => null,
            'response_at' => now(),
        ];
    }

    /**
     * Merchant status: approved
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'response_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    /**
     * Merchant status: pending
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'response_at' => null,
            'reviewed_by' => null,
        ]);
    }

    /**
     * Merchant status: rejected
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'rejection_reason' => $this->faker->sentence(),
            'response_at' => now(),
        ]);
    }

    /**
     * UMKM names related
     *
     * Pastikan nama unik di seluruh tabel: jika nama dasar sudah ada, tambahkan angka.
     */
    public function merchantFactory(): static
    {
        $merchantNames = [
            'Cumi Hitam pak kris',
            'BUMN',
            'Supermarket Flowers',
            'One more light',
            'A million dreams',
            'can i be him',
            'Somewhere i belong',
            'Everything has changed',
            'Warkop SOLO',
            'Waruung Tegal',
            'Warung ngapak',
            'Warung why',
            'Last hope kitchen',
            'Omagaa',
            'UMKM',
        ];

        // pilih nama dasar
        $baseName = $this->faker->randomElement($merchantNames);
        $existingCount = Merchant::where('name', 'LIKE', $baseName . '%')->count();
        $finalName = $existingCount === 0 ? $baseName : $baseName . ' ' . ($existingCount + 1);

        $finalSlug = Str::slug($finalName) . '-' . Str::random(5);

        return $this->state(fn (array $attributes) => [
            'name' => $finalName,
            'slug' => $finalSlug,
        ]);
    }
}
