<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\CommunityPost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CommunityPost>
 */
class CommunityPostFactory extends Factory
{
    protected $model = CommunityPost::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = $this->faker->sentence(rand(4, 8));
        
        return [
            'user_id' => User::inRandomOrder()->first()->id ?? User::factory(),
            'post_title' => rtrim($title, '.'),
            'post_content' => $this->faker->paragraphs(rand(3, 6), true),
            'post_slug' => Str::slug($title) . '-' . Str::random(5),
            'post_status' => $this->faker->randomElement(['published', 'published', 'published', 'draft', 'archived']),
            'views_count' => $this->faker->numberBetween(0, 1000),
        ];
    }

    /**
     * Indicate that the post is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'post_status' => 'published',
        ]);
    }

    /**
     * Indicate that the post is draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'post_status' => 'draft',
        ]);
    }

    /**
     * Indicate that the post is popular (high views).
     */
    public function popular(): static
    {
        return $this->state(fn (array $attributes) => [
            'views_count' => $this->faker->numberBetween(500, 5000),
        ]);
    }

    /**
     * UMKM related titles
     */
    public function postFactory(): static
    {
        $postTitles = [
            'Hello from the other side',
            'I mustve called a thousand times',
            'Hello from the outside',
            'At least I can say that I tried',
            'The hardest part of ending is starting again',
            'Until we dead it, forget it, let it all dissepear',
            'In my fear and flaws, i run',
            'Do all the thing i should have done',
            'When i was your man',
        ];

        $postContents = [
            'To tell you im sorry for everything that Ive done',
            'But when I call you never seem to be home',
            'To tell you Im sorry for breaking your heart',
            'But it dont matter it clearly doesnt tear you apart anymore',
            'Flying at the speed of light',
            'Its hard to let you go',
        ];

        $title = $this->faker->randomElement($postTitles);
        $postContents = $this->faker->randomElements($postContents);

        return $this->state(fn (array $attributes) => [
            'post_title' => $title,
            'post_content' => implode(' ', $postContents),
            'post_slug' => Str::slug($title) . '-' . Str::random(5),
        ]);
    }
}