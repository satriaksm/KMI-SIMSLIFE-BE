<?php

namespace App\Console\Commands;

use App\Models\CommunityPostImage;
use App\Models\Event;
use App\Models\Image;
use App\Models\Jasa;
use App\Models\Merchant;
use App\Models\Paguyuban;
use App\Models\ProductOptionValue;
use App\Models\User;
use App\Services\ImageOptimizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class OptimizeExistingImagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'images:optimize {--force : Optimize even if already webp}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert existing images to WebP format and generate 3 resolutions (thumb, medium, original)';

    protected ImageOptimizationService $imageService;

    public function __construct(ImageOptimizationService $imageService)
    {
        parent::__construct();
        $this->imageService = $imageService;
    }

    public function handle()
    {
        $this->info('Starting image optimization process...');
        $disk = 'public';

        // 1. Optimize Image model (Product, Jasa, etc.)
        $this->optimizeImagesTable($disk);

        // 2. Optimize ProductOptionValue
        $this->optimizeProductOptionValues($disk);

        // 3. Optimize Users (Profile Picture)
        $this->optimizeUsers($disk);

        // 4. Optimize Merchants (Logo & Cover)
        $this->optimizeMerchants($disk);

        // 5. Optimize CommunityPostImage
        $this->optimizeCommunityPostImages($disk);

        // 6. Optimize Events (Non-SVG Banners)
        $this->optimizeEvents($disk);

        // 7. Optimize Paguyubans
        $this->optimizePaguyubans($disk);

        $this->info('Image optimization process completed successfully!');
        return 0;
    }

    private function optimizeImagesTable(string $disk): void
    {
        $this->info('Optimizing images table...');
        $images = Image::whereNotNull('image_path')->get();

        foreach ($images as $img) {
            $this->processModelImagePath($img, 'image_path', $disk, true);
        }
    }

    private function optimizeProductOptionValues(string $disk): void
    {
        $this->info('Optimizing product option values...');
        $values = ProductOptionValue::whereNotNull('image_path')->get();

        foreach ($values as $val) {
            $this->processModelImagePath($val, 'image_path', $disk, true);
        }
    }

    private function optimizeUsers(string $disk): void
    {
        $this->info('Optimizing user profile pictures...');
        $users = User::whereNotNull('profile_picture_path')->get();

        foreach ($users as $user) {
            $this->processModelImagePath($user, 'profile_picture_path', $disk, true);
        }
    }

    private function optimizeMerchants(string $disk): void
    {
        $this->info('Optimizing merchant logos and covers...');
        $merchants = Merchant::all();

        foreach ($merchants as $m) {
            if ($m->logo_path) {
                $this->processModelImagePath($m, 'logo_path', $disk, true);
            }
            if ($m->cover_path) {
                $this->processModelImagePath($m, 'cover_path', $disk, false);
            }
        }
    }

    private function optimizeCommunityPostImages(string $disk): void
    {
        $this->info('Optimizing community post images...');
        $posts = CommunityPostImage::whereNotNull('post_image_path')->get();

        foreach ($posts as $postImg) {
            $this->processModelImagePath($postImg, 'post_image_path', $disk, false);
        }
    }

    private function optimizeEvents(string $disk): void
    {
        $this->info('Optimizing event banners...');
        $events = Event::whereNotNull('banner_img_path')->get();

        foreach ($events as $event) {
            if (str_ends_with(strtolower($event->banner_img_path), '.svg')) {
                continue; // Skip SVGs
            }
            $this->processModelImagePath($event, 'banner_img_path', $disk, false);
        }
    }

    private function optimizePaguyubans(string $disk): void
    {
        $this->info('Optimizing paguyuban images...');
        $paguyubans = Paguyuban::whereNotNull('image_path')->get();

        foreach ($paguyubans as $p) {
            $this->processModelImagePath($p, 'image_path', $disk, false);
        }
    }

    private function processModelImagePath($model, string $attribute, string $disk, bool $isSquare): void
    {
        $oldPath = $model->{$attribute};
        if (empty($oldPath)) {
            return;
        }

        $oldPath = ltrim($oldPath, '/');
        $storage = Storage::disk($disk);

        if (!$storage->exists($oldPath)) {
            return;
        }

        $isWebp = str_ends_with(strtolower($oldPath), '.webp');
        $thumbPath = $this->imageService->resolveSizePath($oldPath, 'thumb');
        $mediumPath = $this->imageService->resolveSizePath($oldPath, 'medium');

        // If already webp and both thumb/medium exist, skip unless forced
        if ($isWebp && $storage->exists($thumbPath) && $storage->exists($mediumPath) && !$this->option('force')) {
            return;
        }

        $fullPath = $storage->path($oldPath);
        $directory = dirname($oldPath);

        try {
            $newRelativePath = $this->imageService->processAndStore($fullPath, $directory, $disk, $isSquare);

            // Update model
            $model->{$attribute} = $newRelativePath;
            $model->save();

            // If old path was different (e.g. was .jpg or .png), remove the old file
            if ($oldPath !== $newRelativePath && $storage->exists($oldPath)) {
                $storage->delete($oldPath);
            }

            $this->line("  [✓] Optimized: {$oldPath} -> {$newRelativePath}");
        } catch (\Throwable $e) {
            $this->error("  [✗] Failed to optimize {$oldPath}: {$e->getMessage()}");
        }
    }
}
