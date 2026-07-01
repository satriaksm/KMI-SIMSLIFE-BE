<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\CommunityPost;
use App\Models\PostComment;
use Illuminate\Database\Seeder;

class PostCommentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all published posts
        $posts = CommunityPost::published()->with('user')->get();

        if ($posts->isEmpty()) {
            $this->command->warn('No published posts found. Please run CommunityPostSeeder first.');
            return;
        }

        // Get all users
        $users = User::all();

        if ($users->isEmpty()) {
            $this->command->warn('No users found. Please run UserSeeder first.');
            return;
        }

        $this->command->info('Creating post comments...');

        $totalComments = 0;
        $totalReplies = 0;

        foreach ($posts as $post) {
            // Random number of comments per post (0-8)
            $commentCount = rand(0, 8);

            if ($commentCount === 0) {
                continue;
            }

            $this->command->info("- Creating {$commentCount} comments for post: {$post->post_title}");

            for ($i = 0; $i < $commentCount; $i++) {
                $comment = PostComment::factory()
                    ->commentFactory($post->post_title)
                    ->create([
                        'post_id' => $post->id,
                        'user_id' => $users->random()->id,
                        'parent_id' => null,
                    ]);

                $totalComments++;

                // 60% chance to have replies
                if (rand(1, 100) <= 60) {
                    $replyCount = rand(1, 3);

                    for ($j = 0; $j < $replyCount; $j++) {
                        $reply = PostComment::factory()
                            ->commentFactory($post->post_title)
                            ->create([
                                'post_id' => $post->id,
                                'user_id' => $users->random()->id,
                                'parent_id' => $comment->id,
                            ]);

                        $totalReplies++;

                        // 30% chance for reply to reply (max 2 levels deep)
                        if (rand(1, 100) <= 30) {
                            PostComment::factory()
                                ->commentFactory($post->post_title)
                                ->create([
                                    'post_id' => $post->id,
                                    'user_id' => $users->random()->id,
                                    'parent_id' => $reply->id,
                                ]);

                            $totalReplies++;
                        }
                    }
                }
            }
        }

        $this->command->info("   Comments seeded successfully!");
        $this->command->info("   Total Comments: {$totalComments}");
        $this->command->info("   Total Replies: {$totalReplies}");
        $this->command->info("   Total: " . ($totalComments + $totalReplies));
    }
}
