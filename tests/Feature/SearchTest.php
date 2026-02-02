<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\Jasa;
use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSegmentations();
    }

    // ======================
    // HELPER METHODS
    // ======================
    protected function createApprovedMerchant()
    {
        $merchant = Merchant::factory()->create([
            'segmentation_id' => 1,
            'status' => 'approved',
        ]);

        return $merchant;
    }

    protected function createProduct(
        Merchant $merchant,
        string $name,
        string $categorySlug,
        int $price = 10000
    ) {
        $category = Category::firstOrCreate(
            ['slug' => $categorySlug],
            ['name' => ucfirst(str_replace('-', ' ', $categorySlug))]
        );

        $product = Product::factory()
            ->create([
                'merchant_id' => $merchant->id,
                'name' => $name,
                'status' => 'published',
            ]);

        $product->variants()->delete();

        // Force deterministic categories
        $product->categories()->sync([$category->id]);

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => $price,
            'stock' => 10,
        ]);

        return $product;
    }

    protected function createJasa(Merchant $merchant, $title = 'Test Jasa', $price = 20000, $categorySlug = 'service-tech')
    {
        $category = Category::firstOrCreate(
            ['slug' => $categorySlug],
            ['name' => ucfirst(str_replace('-', ' ', $categorySlug))]
        );

        $jasa = Jasa::factory()->create([
            'merchant_id' => $merchant->id,
            'title' => $title,
            'fixed_price' => $price,
            'status' => 'active',
            'service_type' => 'on_site',
            'is_active' => true,
        ]);

        $jasa->categories()->sync([$category->id]);

        return $jasa;
    }

    // ====================== //
    // PRODUCTS/JASA SEARCH TESTS
    // ====================== //

    #[Test]
    public function can_search_products_and_jasas()
    {
        $merchant = $this->createApprovedMerchant();

        $this->createProduct($merchant, 'Laptop Gaming', 'elektronik', 15000);
        $this->createProduct($merchant, 'Mouse Wireless', 'elektronik', 5000);

        $this->createJasa($merchant, 'Service Laptop Gaming', 25000, 'elektronik');
        $this->createJasa($merchant, 'Cuci Motor', 10000, 'kendaraan');

        $response = $this->apiGet('/api/public/search', [
            'q' => 'Laptop'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Laptop Gaming',
            ])
            ->assertJsonFragment([
                'name' => 'Service Laptop Gaming',
            ]);
    }

    #[Test]
    public function can_filter_products_and_jasas_by_price_range()
    {
        $merchant = $this->createApprovedMerchant();

        $this->createProduct($merchant, 'Cheap Item', 'makanan', 2000);
        $this->createProduct($merchant, 'Expensive Item', 'elektronik', 30000);

        $this->createJasa($merchant, 'Service Laptop Gaming', 25000, 'elektronik');
        $this->createJasa($merchant, 'Cuci Motor', 10000, 'kendaraan');

        $response = $this->apiGet('/api/public/search', [
            'min_price' => 10000,
            'max_price' => 60000,
        ]);

        $response->assertStatus(200)
            ->assertJsonMissing(['name' => 'Cheap Item'])
            ->assertJsonFragment(['name' => 'Expensive Item']);

        $jasas = $response->json('meta.jasas');

        $this->assertTrue(
            collect($jasas)->contains(fn($j) => $j['name'] === 'Service Laptop Gaming')
        );

        $this->assertTrue(
            collect($jasas)->contains(fn($j) => $j['name'] === 'Cuci Motor')
        );

    }

    #[Test]
    public function can_filter_products_and_jasas_by_category()
    {
        $merchant = $this->createApprovedMerchant();

        $this->createProduct($merchant, 'Laptop Gaming', 'elektronik', 15000);
        $this->createProduct($merchant, 'Bakso Spesial', 'kuliner', 8000);

        $this->createJasa($merchant, 'Service Laptop Gaming', 25000, 'elektronik');
        $this->createJasa($merchant, 'Cuci Motor', 10000, 'kendaraan');

        $response = $this->apiGet('/api/public/search', [
            'categories' => ['elektronik']
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Laptop Gaming'
            ])
            ->assertJsonFragment([
                'name' => 'Service Laptop Gaming'
            ])
            ->assertJsonMissing([
                'name' => 'Bakso Spesial'
            ])
            ->assertJsonMissing([
                'name' => 'Cuci Motor'
            ]);
    }


    #[Test]
    public function can_sort_products_and_jasas_cheapest()
    {
        $merchant = $this->createApprovedMerchant();

        $this->createProduct($merchant, 'Item A', 'elektronik', 30000);
        $this->createProduct($merchant, 'Item B', 'elektronik', 10000);

        $this->createJasa($merchant, 'Service Laptop Gaming', 25000, 'elektronik');
        $this->createJasa($merchant, 'Cuci Motor', 10000, 'kendaraan');

        $response = $this->apiGet('/api/public/search', [
            'sort' => 'cheapest'
        ]);

        $response->assertStatus(200);

        $json = $response->json('data');
        $this->assertEquals('Item B', $json[0]['name']);

        $jasas = $response->json('meta.jasas');

        $this->assertEquals('Cuci Motor', $jasas[0]['name']);

    }

    #[Test]
    public function can_sort_products_and_jasas_expensive()
    {
        $merchant = $this->createApprovedMerchant();

        $this->createProduct($merchant, 'Item A', 'elektronik', 30000);
        $this->createProduct($merchant, 'Item B', 'elektronik', 10000);

        $this->createJasa($merchant, 'Service Laptop Gaming', 25000, 'elektronik');
        $this->createJasa($merchant, 'Cuci Motor', 10000, 'kendaraan');

        $response = $this->apiGet('/api/public/search', [
            'sort' => 'expensive'
        ]);

        $response->assertStatus(200);

        $json = $response->json('data');
        $this->assertEquals('Item A', $json[0]['name']);

        $jasas = $response->json('meta.jasas');
        $this->assertEquals('Service Laptop Gaming', $jasas[0]['name']);

    }

    #[Test]
    public function can_sort_products_and_jasas_nearest()
    {
        $merchantA = $this->createApprovedMerchant();
        $merchantA->update(['latitude' => -6.200000, 'longitude' => 106.816666]); // Jakarta

        $merchantB = $this->createApprovedMerchant();
        $merchantB->update(['latitude' => -7.250445, 'longitude' => 112.768845]); // Surabaya

        $this->createProduct($merchantA, 'Item A', 'elektronik', 30000);
        $this->createProduct($merchantB, 'Item B', 'elektronik', 10000);
        $this->createJasa($merchantA, 'Service Laptop Gaming', 25000, 'elektronik');
        $this->createJasa($merchantB, 'Cuci Motor', 10000, 'kendaraan');

        $response = $this->apiGet('/api/public/search', [
            'sort' => 'nearest',
            'lat' => -6.914744,
            'lng' => 107.609810, // Bandung
        ]);

        $response->assertStatus(200);

        $json = $response->json('data');
        $this->assertEquals('Item A', $json[0]['name']);
    }

    #[Test]
    public function can_sort_products_and_jasas_latest()
    {
        $merchant = $this->createApprovedMerchant();

        $this->createProduct($merchant, 'Old Item', 'elektronik', 15000)->update(['created_at' => now()->subDays(10)]);
        $this->createProduct($merchant, 'New Item', 'elektronik', 15000)->update(['created_at' => now()->subDays(1)]);

        $this->createJasa($merchant, 'Old Jasa', 20000, 'elektronik')->update(['created_at' => now()->subDays(8)]);
        $this->createJasa($merchant, 'New Jasa', 20000, 'elektronik')->update(['created_at' => now()->subDays(2)]);

        $response = $this->apiGet('/api/public/search', [
            'sort' => 'latest'
        ]);

        $response->assertStatus(200);

        $json = $response->json('data');
        $this->assertEquals('New Item', $json[0]['name']);

        $jasas = $response->json('meta.jasas');
        $this->assertEquals('New Jasa', $jasas[0]['name']);

    }

    #[Test]
    public function can_sort_products_and_jasas_oldest()
    {
        $merchant = $this->createApprovedMerchant();

        $this->createProduct($merchant, 'Old Item', 'elektronik', 15000)->update(['created_at' => now()->subDays(10)]);
        $this->createProduct($merchant, 'New Item', 'elektronik', 15000)->update(['created_at' => now()->subDays(1)]);

        $this->createJasa($merchant, 'Old Jasa', 20000, 'elektronik')->update(['created_at' => now()->subDays(8)]);
        $this->createJasa($merchant, 'New Jasa', 20000, 'elektronik')->update(['created_at' => now()->subDays(2)]);

        $response = $this->apiGet('/api/public/search', [
            'sort' => 'oldest'
        ]);

        $response->assertStatus(200);

        $json = $response->json('data');
        $this->assertEquals('Old Item', $json[0]['name']);

        $jasas = $response->json('meta.jasas');
        $this->assertEquals('Old Jasa', $jasas[0]['name']);

    }

    #[Test]
    public function can_include_jasas_in_search()
    {
        $merchant = $this->createApprovedMerchant();

        $this->createProduct($merchant, 'Product A', 'elektronik', 10000);
        $this->createJasa($merchant, 'Service AC', 25000, 'elektronik');

        $response = $this->apiGet('/api/public/search', [
            'include_jasas' => true
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'meta' => ['jasas']
            ]);
    }

    #[Test]
    public function jasa_not_included_if_segment_not_match()
    {
        $merchant = $this->createApprovedMerchant();
        $this->createJasa($merchant, 'Test Jasa', 20000, 'kendaraan');

        $response = $this->apiGet('/api/public/search', [
            'segments' => ['UMKM Toko']
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'meta' => [
                    'jasas' => []
                ]
            ]);
    }

    // ======================
    // VALIDATION TESTS
    // ======================

    #[Test]
    public function search_products_validation_errors()
    {
        $response = $this->apiGet('/api/public/search', [
            'sort' => 'nearest'
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lat', 'lng']);
    }

    #[Test]
    public function invalid_sort_returns_validation_error()
    {
        $response = $this->apiGet('/api/public/search', [
            'sort' => 'random'
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    // ====================== //
    // MERCHANT SEARCH TESTS
    // ====================== //

    #[Test]
    public function can_search_merchants()
    {
        $this->createApprovedMerchant()->update(['name' => 'Toko Elektronik']);
        $this->createApprovedMerchant()->update(['name' => 'Warung Kopi']);

        $response = $this->apiGet('/api/public/search-merchants', [
            'q' => 'Elektronik'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Toko Elektronik'
            ])
            ->assertJsonMissing([
                'name' => 'Warung Kopi'
            ]);
    }

    #[Test]
    public function test_can_filter_merchants_by_segmentation()
    {
        $this->createApprovedMerchant()->update(['name' => 'Toko Elektronik', 'segmentation_id' => 1]);
        $this->createApprovedMerchant()->update(['name' => 'Jasa Kebersihan', 'segmentation_id' => 3]);

        $response = $this->apiGet('/api/public/search-merchants', [
            'segments' => ['UMKM Toko']
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Toko Elektronik'
            ])
            ->assertJsonMissing([
                'name' => 'Jasa Kebersihan'
            ]);
    }

    #[Test]
    public function test_can_filter_merchants_by_isOpen()
    {
        // Merchant buka Senin 08:00-17:00
        $operational_hours = [
            'monday' => [
                'is_open' => true,
                'open' => '08:00',
                'close' => '17:00',
            ],
            'tuesday' => ['is_open' => false],
            'wednesday' => ['is_open' => false],
            'thursday' => ['is_open' => false],
            'friday' => ['is_open' => false],
            'saturday' => ['is_open' => false],
            'sunday' => ['is_open' => false],
        ];

        $merchant = $this->createApprovedMerchant();
        $merchant->update([
            'name' => 'Toko Buka',
            'operational_hours' => $operational_hours,
        ]);

        // Simulasikan waktu server ke Senin 09:00 (buka)
        Carbon::setTestNow(Carbon::parse('next monday 09:00'));
        $response = $this->apiGet('/api/public/search-merchants', [
            'is_open' => 1,
        ]);
        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Toko Buka']);

        // Simulasikan waktu server ke Senin 18:00 (tutup)
        Carbon::setTestNow(Carbon::parse('next monday 18:00'));
        $response = $this->apiGet('/api/public/search-merchants', [
            'is_open' => 1,
        ]);
        $response->assertStatus(200)
            ->assertJsonMissing(['name' => 'Toko Buka']);

        // Reset waktu
        Carbon::setTestNow();
    }
    #[Test]
    public function test_can_sort_merchants_by_nearest()
    {
        $this->createApprovedMerchant()->update(['name' => 'Merchant A', 'latitude' => -6.200000, 'longitude' => 106.816666]); // Jakarta
        $this->createApprovedMerchant()->update(['name' => 'Merchant B', 'latitude' => -7.250445, 'longitude' => 112.768845]); // Surabaya

        $response = $this->apiGet('/api/public/search-merchants', [
            'sort' => 'nearest',
            'lat' => -6.914744,
            'lng' => 107.609810, // Bandung
        ]);

        $response->assertStatus(200);

        $json = $response->json('data');
        $this->assertEquals('Merchant A', $json[0]['name']);
    }

    #[Test]
    public function test_can_sort_merchants_by_latest()
    {
        $this->createApprovedMerchant()->update(['name' => 'Old Merchant', 'created_at' => now()->subDays(10)]);
        $this->createApprovedMerchant()->update(['name' => 'New Merchant', 'created_at' => now()->subDays(1)]);

        $response = $this->apiGet('/api/public/search-merchants', [
            'sort' => 'latest',
        ]);

        $response->assertStatus(200);

        $json = $response->json('data');
        $this->assertEquals('New Merchant', $json[0]['name']);
    }

    #[Test]
    public function test_can_sort_merchants_by_oldest()
    {
        $this->createApprovedMerchant()->update(['name' => 'Old Merchant', 'created_at' => now()->subDays(10)]);
        $this->createApprovedMerchant()->update(['name' => 'New Merchant', 'created_at' => now()->subDays(1)]);

        $response = $this->apiGet('/api/public/search-merchants', [
            'sort' => 'oldest',
        ]);

        $response->assertStatus(200);

        $json = $response->json('data');
        $this->assertEquals('Old Merchant', $json[0]['name']);
    }

    public function test_can_multiple_sort_merchants()
    {
        $this->createApprovedMerchant()->update(['name' => 'Merchant A', 'created_at' => now()->subDays(5)]);
        $this->createApprovedMerchant()->update(['name' => 'Merchant B', 'created_at' => now()->subDays(2)]);
        $this->createApprovedMerchant()->update(['name' => 'Merchant C', 'created_at' => now()->subDays(10)]);

        $response = $this->apiGet('/api/public/search-merchants', [
            'sort' => 'oldest,latest',
        ]);

        $response->assertStatus(200);

        $json = $response->json('data');
        $this->assertEquals('Merchant C', $json[0]['name']);
    }


    #[Test]
    public function search_merchants_validation_errors()
    {
        $response = $this->apiGet('/api/public/search-merchants', [
            'sort' => 'nearest'
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['lat', 'lng']);
    }
}
