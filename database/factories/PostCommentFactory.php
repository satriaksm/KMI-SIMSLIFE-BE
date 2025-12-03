<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\CommunityPost;
use App\Models\PostComment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PostComment>
 */
class PostCommentFactory extends Factory
{
    protected $model = PostComment::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'post_id' => CommunityPost::factory(),
            'user_id' => User::inRandomOrder()->first()->id ?? User::factory(),
            'parent_id' => null, // Top-level comment by default
            'comment_content' => $this->faker->paragraph(rand(1, 3)),
        ];
    }

    /**
     * Indicate that this is a reply to another comment.
     */
    public function reply(?PostComment $parentComment = null): static
    {
        return $this->state(function (array $attributes) use ($parentComment) {
            $parent = $parentComment ?? PostComment::whereNull('parent_id')->inRandomOrder()->first();

            return [
                'parent_id' => $parent?->id,
                'post_id' => $parent?->post_id ?? $attributes['post_id'],
            ];
        });
    }

    /**
     * Short comment.
     */
    public function short(): static
    {
        return $this->state(fn (array $attributes) => [
            'comment_content' => $this->faker->sentence(rand(3, 8)),
        ]);
    }

    /**
     * Long comment.
     */
    public function long(): static
    {
        return $this->state(fn (array $attributes) => [
            'comment_content' => $this->faker->paragraphs(rand(2, 4), true),
        ]);
    }

    /**
     * UMKM related comments.
     */
    public function commentFactory(): static
    {
        $comments = [
            'Time is valuable things, watch it fly by as a pendulum swings',
            'In my fear and flaws, i run',
            'I just wanna lay in my bed',
            'Listen to your heart, those angels voices',
            'Put me out of my fkin misery!!!!!!',
            'Is this the real life? Is this just fantasy?',
            'Give me reasons to believe, that you would do the same for me',
            'ill lve u long after your gone',
            'i will hold on tighter until the aftreglow',
            'we were love drunk and waiting on a miracle',
            'But darlinig id still catch a grenade for you',
            'Falling for the primises of the emptiness machine',
            'Thats why superheroes learn to fly',
            'Oh i remember u driving to my house',
            'in the middle of the night',
            'im the one who makes you laugh',
            'when u know youre about to cry',
            'I know your favorit songs',
            'and u tell me about your dreams',
            'think i know where u belong',
            'think i know its with me...:)',

        ];

        return $this->state(fn (array $attributes) => [
            'comment_content' => $this->faker->randomElement($comments),
        ]);
    }
}
