<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\Jasa;
use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\City;
use App\Models\District;
use App\Models\Province;
use App\Models\ProductVariant;
use App\Models\Village;
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

    protected function forceCreatedAt(string $table, int $id, $createdAt): void
    {
        DB::table($table)->where('id', $id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    protected function createPrimaryAddressForMerchant(Merchant $merchant, float $lat, float $lng): void
    {
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);
        $district = District::factory()->create(['city_id' => $city->id]);
        $village = Village::factory()->create(['district_id' => $district->id]);

        DB::table('addresses')->insert([
            'addressable_id' => $merchant->id,
            'addressable_type' => $merchant->getMorphClass(),

            'province_id' => $province->id,
            'city_id' => $city->id,
            'district_id' => $district->id,
            'village_id' => $village->id,

            'latitude' => $lat,
            'longitude' => $lng,
            'detail' => 'Test address',
            'label' => 'utama',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

        $jasas = $response->json('data.jasas');

        $this->assertIsArray($jasas);

        // If jasa results are returned, they must respect the same price range.
        foreach ($jasas as $j) {
            $price = $j['min_price'] ?? $j['fixed_price'] ?? $j['base_price'] ?? $j['price'] ?? null;
            $this->assertNotNull($price);
            $this->assertGreaterThanOrEqual(10000, (float) $price);
            $this->assertLessThanOrEqual(60000, (float) $price);
        }

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

        $json = $response->json('data.products');
        $this->assertEquals('Item B', $json[0]['name']);

        $jasas = $response->json('data.jasas');

        $this->assertEquals('Cuci Motor', $jasas[0]['name'] ?? $jasas[0]['title'] ?? null);

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

        $json = $response->json('data.products');
        $this->assertEquals('Item A', $json[0]['name']);

        $jasas = $response->json('data.jasas');
        $this->assertEquals('Service Laptop Gaming', $jasas[0]['name'] ?? $jasas[0]['title'] ?? null);

    }

    #[Test]
    public function can_sort_products_and_jasas_nearest()
    {
        $merchantA = $this->createApprovedMerchant();
        $merchantA->update(['latitude' => -6.200000, 'longitude' => 106.816666]); // Jakarta
        $this->createPrimaryAddressForMerchant($merchantA, -6.200000, 106.816666);

        $merchantB = $this->createApprovedMerchant();
        $merchantB->update(['latitude' => -7.250445, 'longitude' => 112.768845]); // Surabaya
        $this->createPrimaryAddressForMerchant($merchantB, -7.250445, 112.768845);

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

        $json = $response->json('data.products');
        $this->assertEquals('Item A', $json[0]['name']);
    }

    #[Test]
    public function can_sort_products_and_jasas_latest()
    {
        $merchant = $this->createApprovedMerchant();

        $oldProduct = $this->createProduct($merchant, 'Old Item', 'elektronik', 15000);
        $newProduct = $this->createProduct($merchant, 'New Item', 'elektronik', 15000);
        $this->forceCreatedAt('products', $oldProduct->id, now()->subDays(10));
        $this->forceCreatedAt('products', $newProduct->id, now()->subDays(1));

        $oldJasa = $this->createJasa($merchant, 'Old Jasa', 20000, 'elektronik');
        $newJasa = $this->createJasa($merchant, 'New Jasa', 20000, 'elektronik');
        $this->forceCreatedAt('jasas', $oldJasa->id, now()->subDays(8));
        $this->forceCreatedAt('jasas', $newJasa->id, now()->subDays(2));

        $response = $this->apiGet('/api/public/search', [
            'sort' => 'latest'
        ]);

        $response->assertStatus(200);

        $json = $response->json('data.products');
        $this->assertEquals('New Item', $json[0]['name']);

        $jasas = $response->json('data.jasas');
        $this->assertEquals('New Jasa', $jasas[0]['name'] ?? $jasas[0]['title'] ?? null);

    }

    #[Test]
    public function can_sort_products_and_jasas_oldest()
    {
        $merchant = $this->createApprovedMerchant();

        $oldProduct = $this->createProduct($merchant, 'Old Item', 'elektronik', 15000);
        $newProduct = $this->createProduct($merchant, 'New Item', 'elektronik', 15000);
        $this->forceCreatedAt('products', $oldProduct->id, now()->subDays(10));
        $this->forceCreatedAt('products', $newProduct->id, now()->subDays(1));

        $oldJasa = $this->createJasa($merchant, 'Old Jasa', 20000, 'elektronik');
        $newJasa = $this->createJasa($merchant, 'New Jasa', 20000, 'elektronik');
        $this->forceCreatedAt('jasas', $oldJasa->id, now()->subDays(8));
        $this->forceCreatedAt('jasas', $newJasa->id, now()->subDays(2));

        $response = $this->apiGet('/api/public/search', [
            'sort' => 'oldest'
        ]);

        $response->assertStatus(200);

        $json = $response->json('data.products');
        $this->assertEquals('Old Item', $json[0]['name']);

        $jasas = $response->json('data.jasas');
        $this->assertEquals('Old Jasa', $jasas[0]['name'] ?? $jasas[0]['title'] ?? null);

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
                'data' => ['jasas']
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
                'data' => [
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
        $merchantA = $this->createApprovedMerchant();
        $merchantA->update(['name' => 'Merchant A', 'latitude' => -6.200000, 'longitude' => 106.816666]); // Jakarta
        $this->createPrimaryAddressForMerchant($merchantA, -6.200000, 106.816666);

        $merchantB = $this->createApprovedMerchant();
        $merchantB->update(['name' => 'Merchant B', 'latitude' => -7.250445, 'longitude' => 112.768845]); // Surabaya
        $this->createPrimaryAddressForMerchant($merchantB, -7.250445, 112.768845);

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
        $old = $this->createApprovedMerchant();
        $old->update(['name' => 'Old Merchant']);
        $new = $this->createApprovedMerchant();
        $new->update(['name' => 'New Merchant']);
        $this->forceCreatedAt('merchants', $old->id, now()->subDays(10));
        $this->forceCreatedAt('merchants', $new->id, now()->subDays(1));

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
        $old = $this->createApprovedMerchant();
        $old->update(['name' => 'Old Merchant']);
        $new = $this->createApprovedMerchant();
        $new->update(['name' => 'New Merchant']);
        $this->forceCreatedAt('merchants', $old->id, now()->subDays(10));
        $this->forceCreatedAt('merchants', $new->id, now()->subDays(1));

        $response = $this->apiGet('/api/public/search-merchants', [
            'sort' => 'oldest',
        ]);

        $response->assertStatus(200);

        $json = $response->json('data');
        $this->assertEquals('Old Merchant', $json[0]['name']);
    }

    public function test_can_multiple_sort_merchants()
    {
        // Search merchants endpoint only accepts a single sort value (latest/oldest/nearest).
        // Comma-separated sorts are invalid and should return validation error.
        $response = $this->apiGet('/api/public/search-merchants', [
            'sort' => 'oldest,latest',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
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
