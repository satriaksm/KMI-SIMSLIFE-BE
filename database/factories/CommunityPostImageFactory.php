<?php

namespace Database\Factories;

use App\Models\CommunityPost;
use App\Models\CommunityPostImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CommunityPostImage>
 */
class CommunityPostImageFactory extends Factory
{
    protected $model = CommunityPostImage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => CommunityPost::factory(),
            'post_image_path' => 'community/posts/placeholder/' . $this->faker->numberBetween(1, 10) . '.jpg',
            'alt_text' => null, 
        ];
    }
}