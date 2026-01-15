<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('now', '+30 days');
        $endDate = Carbon::parse($startDate)->addDays(rand(3, 7));
        $today = Carbon::today();

        // ✅ Set status berdasarkan tanggal
        $status = 'draft'; // Default
        if (Carbon::parse($startDate) <= $today && Carbon::parse($endDate) >= $today) {
            $status = 'published'; // Hanya published jika dalam rentang
        } elseif (Carbon::parse($endDate) < $today) {
            $status = 'archived'; // Archived jika sudah lewat
        }

        return [
            'event_name' => 'Event ' . $this->faker->unique()->randomNumber(1) . ' - ' . $this->faker->lexify('??????'),
            'event_description' => 'Deskripsi event ke-' . $this->faker->randomNumber(1),
            'event_start_date' => $startDate,
            'event_end_date' => $endDate,
            'banner_img_path' => null,
            'status' => $status,
            'created_by' => User::whereHas('roles', fn($q) => $q->where('name', 'admin'))->first()?->id ?? 1,
        ];
    }

    /**
     * State untuk event yang aktif (published)
     */
    public function active(): static
    {
        return $this->state(function (array $attributes) {
            $startDate = Carbon::now()->subDays(2);
            $endDate = Carbon::now()->addDays(5);

            return [
                'event_start_date' => $startDate,
                'event_end_date' => $endDate,
                'status' => 'published',
            ];
        });
    }

    /**
     * State untuk event yang belum dimulai (draft)
     */
    public function upcoming(): static
    {
        return $this->state(function (array $attributes) {
            $startDate = Carbon::now()->addDays(5);
            $endDate = Carbon::now()->addDays(10);

            return [
                'event_start_date' => $startDate,
                'event_end_date' => $endDate,
                'status' => 'draft',
            ];
        });
    }

    /**
     * State untuk event yang sudah berakhir (archived)
     */
    public function past(): static
    {
        return $this->state(function (array $attributes) {
            $startDate = Carbon::now()->subDays(10);
            $endDate = Carbon::now()->subDays(3);

            return [
                'event_start_date' => $startDate,
                'event_end_date' => $endDate,
                'status' => 'archived',
            ];
        });
    }
}
