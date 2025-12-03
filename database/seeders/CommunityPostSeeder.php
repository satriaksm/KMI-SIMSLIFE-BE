<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\CommunityPost;
use App\Models\CommunityPostImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CommunityPostSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure storage directory exists
        $this->ensureStorageDirectoryExists();

        // Get all users with customer or merchant role
        $customers = User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['customer', 'merchant']);
        })->get();

        if ($customers->isEmpty()) {
            $this->command->warn('No customers or merchants found. Creating sample users...');
            $customers = User::factory()->count(5)->create();
        }

        // Get admin user 
        $admin = User::whereHas('roles', function ($query) {
            $query->where('name', 'admin');
        })->first();

        $this->command->info('Creating community posts...');

        $this->command->info('- Creating popular posts (10)...');
        $customers->random(min(5, $customers->count()))->each(function ($user) {
            CommunityPost::factory()
                ->count(2)
                ->published()
                ->popular()
                ->postFactory()
                ->create(['user_id' => $user->id])
                ->each(function ($post) {
                    $this->createImagesForPost($post, rand(1, 3));
                });
        });

        //publisheds
        $this->command->info('Creating published posts (30)...');
        $customers->each(function ($user) {
            $postCount = rand(2, 8);
            CommunityPost::factory()
                ->count($postCount)
                ->published()
                ->postFactory()
                ->create(['user_id' => $user->id])
                ->each(function ($post) {
                    // Random: some with images, some without
                    if (rand(1, 100) <= 70) { // 70% posts have images
                        $imageCount = rand(0, 5);
                        if ($imageCount > 0) {
                            $this->createImagesForPost($post, $imageCount);
                        }
                    }
                });
        });

        // drafts
        $this->command->info('Creating draft posts (5)...');
        $customers->random(min(3, $customers->count()))->each(function ($user) {
            CommunityPost::factory()
                ->count(rand(1, 2))
                ->draft()
                ->postFactory()
                ->create(['user_id' => $user->id])
                ->each(function ($post) {
                    if (rand(1, 100) <= 50) {
                        $this->createImagesForPost($post, rand(1, 3));
                    }
                });
        });

        // admins posts
        if ($admin) {
            $this->command->info('Creating admin announcement posts (3)...');
            CommunityPost::factory()
                ->count(3)
                ->published()
                ->postFactory()
                ->create(['user_id' => $admin->id])
                ->each(function ($post) {
                    $this->createImagesForPost($post, rand(1, 2));
                });
        }

        $totalPosts = CommunityPost::count();
        $totalImages = CommunityPostImage::count();
        
        $this->command->info("Community posts seeded successfully...");
        $this->command->info("   Total Posts: {$totalPosts}");
        $this->command->info("   Total Images: {$totalImages}");
    }

    /**
     * Create images for a post with auto-generated alt text
     */
    private function createImagesForPost(CommunityPost $post, int $count): void
    {
        $postTitleSlug = Str::slug($post->post_title);

        for ($i = 1; $i <= $count; $i++) {
            // Create placeholder image path
            $imagePath = $this->createPlaceholderImage($post->id, $i);

            CommunityPostImage::create([
                'post_id' => $post->id,
                'post_image_path' => $imagePath,
                'alt_text' => "{$postTitleSlug}-{$i}",
            ]);
        }
    }

    /**
     * Create placeholder image using external service
     */
    private function createPlaceholderImage(int $postId, int $index): string
    {
        $directory = "community/posts/{$postId}";
        Storage::disk('public')->makeDirectory($directory);

        $filename = Str::random(20) . '.jpg';
        $fullPath = "{$directory}/{$filename}";

        try {
            // Download image from Lorem Picsum (random image service)
            $imageUrl = "https://picsum.photos/800/600?random={$postId}{$index}";
            $imageContent = file_get_contents($imageUrl);
            
            if ($imageContent !== false) {
                Storage::disk('public')->put($fullPath, $imageContent);
            } else {
                $this->createFallbackImage($fullPath);
            }
        } catch (\Exception $e) {
            $this->createFallbackImage($fullPath);
        }

        return $fullPath;
    }

    private function createFallbackImage(string $path): void
    {
        $placeholderContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        Storage::disk('public')->put($path, $placeholderContent);
    }

    /**
     * Ensure storage directory exists
     */
    private function ensureStorageDirectoryExists(): void
    {
        $publicPath = storage_path('app/public/community/posts');
        
        if (!File::exists($publicPath)) {
            File::makeDirectory($publicPath, 0755, true);
        }
    }
}