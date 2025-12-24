<?php

namespace Database\Seeders;

use App\Models\ContentReport;
use App\Models\User;
use App\Models\CommunityPost;
use App\Models\PostComment;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ContentReportSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating content reports...');

        $users = User::all();
        $posts = CommunityPost::published()->get();
        $comments = PostComment::all();
        $products = Product::published()->get();

        if ($users->isEmpty() || $posts->isEmpty() || $products->isEmpty()) {
            $this->command->warn('Insufficient data. Run UserSeeder, CommunityPostSeeder, and ProductSeeder first.');
            return;
        }

        $totalReports = 0;

        // Reports for posts (10-15 pending)
        $this->command->info('- Creating reports for posts...');
        for ($i = 0; $i < rand(10, 15); $i++) {
            ContentReport::factory()
                ->forPost()
                ->pending()
                ->create([
                    'user_id' => $users->random()->id,
                ]);
            $totalReports++;
        }

        // Reports for comments (5-10 pending)
        if ($comments->isNotEmpty()) {
            $this->command->info('- Creating reports for comments...');
            for ($i = 0; $i < rand(5, 10); $i++) {
                ContentReport::factory()
                    ->forComment()
                    ->pending()
                    ->create([
                        'user_id' => $users->random()->id,
                    ]);
                $totalReports++;
            }
        }

        // Reports for products (3-5 pending)
        if ($products->isNotEmpty()) {
            $this->command->info('- Creating reports for products...');
            for ($i = 0; $i < rand(3, 5); $i++) {
                ContentReport::factory()
                    ->forProduct()
                    ->pending()
                    ->create([
                        'user_id' => $users->random()->id,
                    ]);
                $totalReports++;
            }
        }

        // Some resolved reports (5)
        $this->command->info('- Creating resolved reports...');
        for ($i = 0; $i < 5; $i++) {
            ContentReport::factory()
                ->forPost()
                ->resolved()
                ->create([
                    'user_id' => $users->random()->id,
                ]);
            $totalReports++;
        }

        // Some dismissed reports (3)
        $this->command->info('- Creating dismissed reports...');
        for ($i = 0; $i < 3; $i++) {
            ContentReport::factory()
                ->forPost()
                ->dismissed()
                ->create([
                    'user_id' => $users->random()->id,
                ]);
            $totalReports++;
        }

        $this->command->info("Content reports seeded successfully!");
        $this->command->info("   Total: {$totalReports}");
    }
}
