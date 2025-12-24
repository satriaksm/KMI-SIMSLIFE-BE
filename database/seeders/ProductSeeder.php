<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Merchant;
use App\Models\Category;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🛍️ Creating products...');

        $merchants = Merchant::where('status', 'approved')->get();

        if ($merchants->isEmpty()) {
            $this->command->warn('⚠️ No approved merchants found. Run MerchantSeeder first.');
            return;
        }

        $leafCategories = Category::whereNotNull('parent_id')->get();

        if ($leafCategories->isEmpty()) {
            $this->command->warn('⚠️ No subcategories found. Run CategorySeeder first.');
            return;
        }

        $totalProducts = 0;

        foreach ($merchants as $merchant) {
            $productCount = rand(3, 10);
            $this->command->info("  - Creating {$productCount} products for {$merchant->name}");
            for ($i = 0; $i < $productCount; $i++) {
                $product = Product::factory()
                    ->published()
                    ->productFactory()
                    ->create([
                        'merchant_id' => $merchant->id,
                    ]);

                // Ambil 1-3 kategori random untuk setiap produk
                $randomCategories = $leafCategories->random(rand(1, min(3, $leafCategories->count())));

                // Attach ke kategori lewat morphToMany (categorizables)
                foreach ($randomCategories as $category) {
                    $product->categories()->attach($category->id);
                }

                // Create product variant
                ProductVariant::create([
                    'product_id' => $product->id,
                    'stock' => rand(10, 100),
                    'sku' => 'SKU-' . strtoupper(substr(md5($product->id . time()), 0, 8)),
                    'price' => rand(10000, 500000),
                ]);

                // Create product images
                $imageCount = rand(1, 3);
                for ($j = 0; $j < $imageCount; $j++) {
                    $product->images()->create([
                        'image_path' => "products/placeholder/{$product->id}_{$j}.jpg",
                        'display_order' => $j,
                        'is_cover' => $j === 0,
                    ]);
                }
                $totalProducts++;
            }
        }

        $this->command->info(" Products seeded successfully!");
        $this->command->info("    Total products: {$totalProducts}");
        $this->command->info("    Categories used: " . $leafCategories->count());
    }
}
