<?php

namespace Database\Factories;

use App\Models\ContentReport;
use App\Models\User;
use App\Models\ReportReason;
use App\Models\CommunityPost;
use App\Models\PostComment;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentReportFactory extends Factory
{
    protected $model = ContentReport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::inRandomOrder()->first()?->id ?? User::factory(),
            'report_reason_id' => ReportReason::inRandomOrder()->first()?->id ?? 1,
            'reportable_type' => CommunityPost::class,
            'reportable_id' => CommunityPost::inRandomOrder()->first()?->id ?? CommunityPost::factory(),
            'report_comment' => $this->faker->optional()->sentence(),
            'status' => 'pending',
            'reviewed_by' => null,
            'admin_note' => null,
            'reviewed_at' => null,
            'created_at' => $date = $this->faker->dateTimeBetween('2026-01-01', '2026-07-12'),
            'updated_at' => $date,
        ];
    }

    /**
     * Report for CommunityPost
     */
    public function forPost(): static
    {
        return $this->state(fn (array $attributes) => [
            'reportable_type' => CommunityPost::class,
            'reportable_id' => CommunityPost::published()->inRandomOrder()->first()?->id ?? CommunityPost::factory(),
            'report_reason_id' => ReportReason::where('applies_to', 'post')->inRandomOrder()->first()?->id ?? 1,
        ]);
    }

    /**
     * Report for PostComment
     */
    public function forComment(): static
    {
        return $this->state(fn (array $attributes) => [
            'reportable_type' => PostComment::class,
            'reportable_id' => PostComment::inRandomOrder()->first()?->id ?? PostComment::factory(),
            'report_reason_id' => ReportReason::where('applies_to', 'post_comment')->inRandomOrder()->first()?->id ?? 1,
        ]);
    }

    /**
     * Report for Product
     */
    public function forProduct(): static
    {
        return $this->state(fn (array $attributes) => [
            'reportable_type' => Product::class,
            'reportable_id' => Product::published()->inRandomOrder()->first()?->id ?? Product::factory(),
            'report_reason_id' => ReportReason::where('applies_to', 'product')->inRandomOrder()->first()?->id ?? 1,
        ]);
    }

    /**
     * Status: pending
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'reviewed_by' => null,
            'admin_note' => null,
            'reviewed_at' => null,
        ]);
    }

    /**
     * Status: resolved
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'resolved',
            'reviewed_by' => User::whereHas('roles', fn($q) => $q->where('name', 'admin'))->first()?->id,
            'admin_note' => $this->faker->sentence(),
            'reviewed_at' => fn (array $attributes) => $this->faker->dateTimeBetween($attributes['created_at'], '2026-07-12'),
            'updated_at' => fn (array $attributes) => $attributes['reviewed_at'],
        ]);
    }

    /**
     * Status: dismissed
     */
    public function dismissed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'dismissed',
            'reviewed_by' => User::whereHas('roles', fn($q) => $q->where('name', 'admin'))->first()?->id,
            'admin_note' => $this->faker->sentence(),
            'reviewed_at' => fn (array $attributes) => $this->faker->dateTimeBetween($attributes['created_at'], '2026-07-12'),
            'updated_at' => fn (array $attributes) => $attributes['reviewed_at'],
        ]);
    }
}