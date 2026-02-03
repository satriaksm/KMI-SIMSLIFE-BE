<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Image;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedRoles();
        $this->seedSegmentations();
    }

    protected function createUserWithRole(int $roleId, bool $verified = true): User
    {
        $user = User::factory()->create([
            'email_verified_at' => $verified ? now() : null,
        ]);
        $user->roles()->syncWithoutDetaching([$roleId]);
        return $user;
    }

    protected function createMerchantOwnedBy(User $owner, array $overrides = []): Merchant
    {
        return Merchant::factory()->create(array_merge([
            'user_id' => $owner->id,
            'segmentation_id' => 1,
            'status' => 'approved',
        ], $overrides));
    }

    /**
     * @return array{0: Category, 1: Category}
     */
    protected function createTwoCategories(): array
    {
        $cat1 = Category::factory()->create();
        $cat2 = Category::factory()->create();

        return [$cat1, $cat2];
    }

    protected function createPublishedProductWithVariant(Merchant $merchant, array $overrides = [], int $price = 10000, int $stock = 10): Product
    {
        $product = Product::factory()->create(array_merge([
            'merchant_id' => $merchant->id,
            'status' => 'published',
        ], $overrides));

        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => $price,
            'stock' => $stock,
        ]);

        return $product;
    }

    protected function merchantProductsBaseUrl(Merchant $merchant): string
    {
        return '/api/merchant/' . $merchant->slug . '/products';
    }

    // ======================
    // PUBLIC ENDPOINTS
    // ======================

    #[Test]
    public function public_show_returns_product_payload_for_published_product_and_approved_merchant()
    {
        $merchant = Merchant::factory()->approved()->create([
            'status' => 'approved',
        ]);
        $product = $this->createPublishedProductWithVariant($merchant);

        $this->getJson('/api/public/products/' . $product->slug)
            ->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'product',
                    'merchant' => ['id', 'name', 'slug'],
                    'related_products',
                ],
            ])
            ->assertJsonPath('data.product.slug', $product->slug)
            ->assertJsonPath('data.merchant.slug', $merchant->slug);
    }

    #[Test]
    public function public_show_returns_404_for_non_published_product()
    {
        $merchant = Merchant::factory()->approved()->create([
            'status' => 'approved',
        ]);
        $product = Product::factory()->draft()->create([
            'merchant_id' => $merchant->id,
            'status' => 'draft',
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product->id,
            'stock' => 10,
        ]);

        $this->getJson('/api/public/products/' . $product->slug)
            ->assertStatus(404);
    }

    #[Test]
    public function public_show_returns_404_for_non_approved_merchant()
    {
        $merchant = Merchant::factory()->pending()->create([
            'status' => 'pending',
        ]);
        $product = $this->createPublishedProductWithVariant($merchant);

        $this->getJson('/api/public/products/' . $product->slug)
            ->assertStatus(404);
    }

    #[Test]
    public function public_by_merchant_lists_only_published_in_stock_products_and_supports_search()
    {
        $merchant = Merchant::factory()->approved()->create([
            'status' => 'approved',
        ]);

        $inStock = $this->createPublishedProductWithVariant($merchant, ['name' => 'Nasi Goreng Spesial']);
        $outOfStock = $this->createPublishedProductWithVariant($merchant, ['name' => 'Sate Ayam'], price: 12000, stock: 0);
        $draft = Product::factory()->draft()->create([
            'merchant_id' => $merchant->id,
            'status' => 'draft',
            'name' => 'Mie Ayam',
        ]);
        ProductVariant::factory()->create([
            'product_id' => $draft->id,
            'price' => 9000,
            'stock' => 10,
        ]);

        $resp = $this->getJson('/api/public/merchants/' . $merchant->slug . '/products');
        $resp->assertStatus(200)->assertJsonStructure([
            'message',
            'data' => [
                '*' => ['id', 'name', 'slug', 'min_price', 'max_price', 'cover_image'],
            ],
            'meta' => ['current_page', 'last_page', 'total'],
        ]);

        $ids = collect($resp->json('data'))->pluck('id')->all();
        $this->assertContains($inStock->id, $ids);
        $this->assertNotContains($outOfStock->id, $ids);
        $this->assertNotContains($draft->id, $ids);

        $respQ = $this->getJson('/api/public/merchants/' . $merchant->slug . '/products?q=Nasi');
        $respQ->assertStatus(200);
        $idsQ = collect($respQ->json('data'))->pluck('id')->all();
        $this->assertContains($inStock->id, $idsQ);
        $this->assertNotContains($outOfStock->id, $idsQ);
    }

    // ======================
    // MERCHANT OWNER: ACCESS / POLICY
    // ======================

    #[Test]
    public function guest_cannot_access_merchant_product_endpoints()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);

        $this->apiGet($this->merchantProductsBaseUrl($merchant))->assertStatus(401);
        $this->apiPost($this->merchantProductsBaseUrl($merchant), [])->assertStatus(401);
        $this->apiGet($this->merchantProductsBaseUrl($merchant) . '/export/excel')->assertStatus(401);
    }

    #[Test]
    public function non_umkm_owner_cannot_access_merchant_product_endpoints()
    {
        $customer = $this->createUserWithRole(3);
        $merchant = $this->createMerchantOwnedBy($this->createUserWithRole(2));

        $this->actingAs($customer)
            ->apiGet($this->merchantProductsBaseUrl($merchant))
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Forbidden.']);
    }

    #[Test]
    public function unverified_umkm_owner_cannot_access_merchant_product_endpoints()
    {
        $owner = $this->createUserWithRole(2, verified: false);
        $merchant = $this->createMerchantOwnedBy($owner);

        $this->actingAs($owner)
            ->apiGet($this->merchantProductsBaseUrl($merchant))
            ->assertStatus(403);
    }

    #[Test]
    public function umkm_owner_cannot_manage_products_for_other_users_merchant()
    {
        $owner = $this->createUserWithRole(2);
        $otherOwner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($otherOwner);

        $this->actingAs($owner)
            ->apiGet($this->merchantProductsBaseUrl($merchant))
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'UMKM tidak sah. Anda bukan pemilik UMKM ini.']);
    }

    #[Test]
    public function umkm_owner_cannot_manage_products_for_pending_merchant()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner, ['status' => 'pending']);

        $this->actingAs($owner)
            ->apiGet($this->merchantProductsBaseUrl($merchant))
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'UMKM belum disetujui oleh admin.']);
    }

    #[Test]
    public function umkm_owner_cannot_manage_products_for_disallowed_segment()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner, ['segmentation_id' => 3, 'status' => 'approved']);

        $this->actingAs($owner)
            ->apiGet($this->merchantProductsBaseUrl($merchant))
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Segment UMKM tidak diizinkan untuk mengelola produk.']);
    }

    // ======================
    // MERCHANT OWNER: INDEX
    // ======================

    #[Test]
    public function umkm_owner_can_list_products_with_meta_and_filters()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        $otherMerchant = $this->createMerchantOwnedBy($owner, ['name' => 'Other', 'slug' => 'other-' . uniqid()]);

        $p1 = $this->createPublishedProductWithVariant($merchant, ['name' => 'Produk A', 'status' => 'published'], price: 10000, stock: 5);
        $p2 = $this->createPublishedProductWithVariant($merchant, ['name' => 'Produk B', 'status' => 'archived'], price: 20000, stock: 8);
        $p3 = $this->createPublishedProductWithVariant($otherMerchant, ['name' => 'Produk C', 'status' => 'published'], price: 15000, stock: 5);

        $resp = $this->actingAs($owner)->apiGet($this->merchantProductsBaseUrl($merchant), [
            'q' => 'Produk',
            'status' => 'published',
            'per_page' => 50,
        ]);

        $resp->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => ['id', 'merchant_id', 'name', 'status', 'slug', 'total_stock', 'min_price', 'max_price'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        $ids = collect($resp->json('data'))->pluck('id')->all();
        $this->assertContains($p1->id, $ids);
        $this->assertNotContains($p2->id, $ids);
        $this->assertNotContains($p3->id, $ids);
    }

    // ======================
    // MERCHANT OWNER: STORE
    // ======================

    #[Test]
    public function umkm_owner_can_store_simple_product_without_variants_and_without_images()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        [$cat1, $cat2] = $this->createTwoCategories();

        $resp = $this->actingAs($owner)->apiPost($this->merchantProductsBaseUrl($merchant), [
            'name' => 'Produk Simple',
            'description' => 'Deskripsi',
            'min_purchase' => 1,
            'status' => 'draft',
            'price' => 12345,
            'stock' => 7,
            'sku' => 'SKU-TEST',
            'category_ids' => [$cat1->id, $cat2->id],
        ]);

        $resp->assertStatus(201)
            ->assertJsonFragment(['message' => 'Produk berhasil dibuat'])
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'merchant_id', 'name', 'slug', 'status'],
            ]);

        $productId = (int) $resp->json('data.id');
        $product = Product::query()->with(['variants', 'categories', 'images'])->findOrFail($productId);
        $this->assertSame($merchant->id, $product->merchant_id);
        $this->assertCount(1, $product->variants);
        $this->assertCount(2, $product->categories);
        $this->assertCount(0, $product->images);
    }

    #[Test]
    public function store_fails_when_missing_price_or_stock_without_variants()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        [$cat1] = $this->createTwoCategories();

        $this->actingAs($owner)
            ->apiPost($this->merchantProductsBaseUrl($merchant), [
                'name' => 'Produk Invalid',
                'category_ids' => [$cat1->id],
                'price' => 1000,
                // stock missing
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Harga dan stok wajib diisi jika tidak menggunakan variasi.']);
    }

    #[Test]
    public function store_fails_when_variants_present_but_no_combinations()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        [$cat1] = $this->createTwoCategories();

        $this->actingAs($owner)
            ->apiPost($this->merchantProductsBaseUrl($merchant), [
                'name' => 'Produk Varian',
                'category_ids' => [$cat1->id],
                'variants' => [
                    [
                        'name' => 'Size',
                        'uses_images' => 0,
                        'options' => [
                            ['name' => 'S'],
                            ['name' => 'M'],
                        ],
                    ]
                ],
                // combinations missing
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Kombinasi variasi wajib diisi jika menggunakan variasi.']);
    }

    #[Test]
    public function store_fails_when_variants_but_no_variant_has_two_options()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        [$cat1] = $this->createTwoCategories();

        $this->actingAs($owner)
            ->apiPost($this->merchantProductsBaseUrl($merchant), [
                'name' => 'Produk Varian',
                'category_ids' => [$cat1->id],
                'variants' => [
                    [
                        'name' => 'Size',
                        'uses_images' => 0,
                        'options' => [
                            ['name' => 'S'],
                        ],
                    ]
                ],
                'combinations' => [
                    [
                        'combination' => 'Size: S',
                        'sku' => 'SKU-S',
                        'price' => 1000,
                        'stock' => 1,
                        'attributes' => [
                            ['option_value_id' => null, 'name' => 'Size', 'value' => 'S'],
                        ],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Jika menggunakan variasi, minimal salah satu varian harus memiliki 2 pilihan atau lebih.',
            ]);
    }

    #[Test]
    public function store_fails_when_variant_names_duplicate()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        [$cat1] = $this->createTwoCategories();

        $this->actingAs($owner)
            ->apiPost($this->merchantProductsBaseUrl($merchant), [
                'name' => 'Produk Varian Duplicate',
                'category_ids' => [$cat1->id],
                'variants' => [
                    [
                        'name' => 'Size',
                        'uses_images' => 0,
                        'options' => [
                            ['name' => 'S'],
                            ['name' => 'M'],
                        ],
                    ],
                    [
                        'name' => ' size ',
                        'uses_images' => 0,
                        'options' => [
                            ['name' => 'A'],
                            ['name' => 'B'],
                        ],
                    ],
                ],
                'combinations' => [
                    [
                        'combination' => 'Size: S',
                        'sku' => 'SKU-1',
                        'price' => 1000,
                        'stock' => 1,
                        'attributes' => [
                            ['option_value_id' => null, 'name' => 'Size', 'value' => 'S'],
                        ],
                    ],
                    [
                        'combination' => 'Size: M',
                        'sku' => 'SKU-2',
                        'price' => 1100,
                        'stock' => 2,
                        'attributes' => [
                            ['option_value_id' => null, 'name' => 'Size', 'value' => 'M'],
                        ],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Nama varian tidak boleh sama.']);
    }

    #[Test]
    public function store_fails_when_combinations_duplicate()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        [$cat1] = $this->createTwoCategories();

        $this->actingAs($owner)
            ->apiPost($this->merchantProductsBaseUrl($merchant), [
                'name' => 'Produk Varian Duplicate Combo',
                'category_ids' => [$cat1->id],
                'variants' => [
                    [
                        'name' => 'Size',
                        'uses_images' => 0,
                        'options' => [
                            ['name' => 'S'],
                            ['name' => 'M'],
                        ],
                    ],
                ],
                'combinations' => [
                    [
                        'combination' => 'Size: S',
                        'sku' => 'SKU-1',
                        'price' => 1000,
                        'stock' => 1,
                        'attributes' => [
                            ['option_value_id' => null, 'name' => 'Size', 'value' => 'S'],
                        ],
                    ],
                    [
                        'combination' => 'Size: S',
                        'sku' => 'SKU-2',
                        'price' => 1000,
                        'stock' => 1,
                        'attributes' => [
                            ['option_value_id' => null, 'name' => 'Size', 'value' => 'S'],
                        ],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Kombinasi variasi tidak boleh duplikat.']);
    }

    #[Test]
    public function store_fails_when_too_many_images()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        [$cat1] = $this->createTwoCategories();

        $images = [];
        for ($i = 0; $i < 7; $i++) {
            $images[] = [
                'file' => UploadedFile::fake()->image("p{$i}.jpg"),
                'order' => $i,
            ];
        }

        $this->actingAs($owner)
            ->apiPost($this->merchantProductsBaseUrl($merchant), [
                'name' => 'Produk Images',
                'category_ids' => [$cat1->id],
                'price' => 1000,
                'stock' => 1,
                'images' => $images,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Maksimal upload 6 foto produk.']);
    }

    #[Test]
    public function store_fails_when_addon_group_min_greater_than_max()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        [$cat1] = $this->createTwoCategories();

        $this->actingAs($owner)
            ->apiPost($this->merchantProductsBaseUrl($merchant), [
                'name' => 'Produk Addon',
                'category_ids' => [$cat1->id],
                'price' => 1000,
                'stock' => 1,
                'add_on_groups' => [
                    [
                        'name' => 'Topping',
                        'min_selection' => 2,
                        'max_selection' => 1,
                        'options' => [
                            ['name' => 'A', 'price' => 100],
                        ],
                    ]
                ],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Grup add-on #0: minimal pilihan tidak boleh lebih besar dari maksimal.']);
    }

    #[Test]
    public function store_fails_when_addon_group_names_duplicate()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        [$cat1] = $this->createTwoCategories();

        $this->actingAs($owner)
            ->apiPost($this->merchantProductsBaseUrl($merchant), [
                'name' => 'Produk Addon Duplicate',
                'category_ids' => [$cat1->id],
                'price' => 1000,
                'stock' => 1,
                'add_on_groups' => [
                    [
                        'name' => 'Topping',
                        'min_selection' => 0,
                        'max_selection' => 1,
                        'options' => [
                            ['name' => 'A', 'price' => 100],
                        ],
                    ],
                    [
                        'name' => ' topping ',
                        'min_selection' => 0,
                        'max_selection' => 1,
                        'options' => [
                            ['name' => 'B', 'price' => 200],
                        ],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Nama grup add-on tidak boleh sama.']);
    }

    #[Test]
    public function store_fails_when_addon_option_names_duplicate_in_one_group()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        [$cat1] = $this->createTwoCategories();

        $this->actingAs($owner)
            ->apiPost($this->merchantProductsBaseUrl($merchant), [
                'name' => 'Produk Addon Option Duplicate',
                'category_ids' => [$cat1->id],
                'price' => 1000,
                'stock' => 1,
                'add_on_groups' => [
                    [
                        'name' => 'Topping',
                        'min_selection' => 0,
                        'max_selection' => 2,
                        'options' => [
                            ['name' => 'Keju', 'price' => 100],
                            ['name' => ' keju ', 'price' => 150],
                        ],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "Nama opsi add-on pada grup 'Topping' tidak boleh sama."]);
    }

    // ======================
    // MERCHANT OWNER: SHOW / UPDATE / STATUS / DESTROY
    // ======================

    #[Test]
    public function umkm_owner_can_show_product_by_slug()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        $product = $this->createPublishedProductWithVariant($merchant);

        $this->actingAs($owner)
            ->apiGet($this->merchantProductsBaseUrl($merchant) . '/' . $product->slug)
            ->assertStatus(200)
            ->assertJsonPath('data.slug', $product->slug);
    }

    #[Test]
    public function show_returns_404_when_product_does_not_belong_to_merchant()
    {
        $owner = $this->createUserWithRole(2);
        $merchantA = $this->createMerchantOwnedBy($owner, ['slug' => 'a-' . uniqid()]);
        $merchantB = $this->createMerchantOwnedBy($owner, ['slug' => 'b-' . uniqid()]);
        $productA = $this->createPublishedProductWithVariant($merchantA);

        $this->actingAs($owner)
            ->apiGet($this->merchantProductsBaseUrl($merchantB) . '/' . $productA->slug)
            ->assertStatus(404);
    }

    #[Test]
    public function umkm_owner_can_update_product_basic_fields_and_categories()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        $product = $this->createPublishedProductWithVariant($merchant);
        [$cat1, $cat2] = $this->createTwoCategories();

        $resp = $this->actingAs($owner)
            ->apiPut($this->merchantProductsBaseUrl($merchant) . '/' . $product->slug, [
                'name' => 'Nama Baru',
                'description' => 'Deskripsi Baru',
                'min_purchase' => 2,
                'status' => 'published',
                'category_id' => $cat1->id,
                'sub_categories' => [$cat2->id],
                'sku' => 'SKU-UPDATED',
                'price' => 5555,
                'stock' => 9,
            ]);

        $resp->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Nama Baru',
                'description' => 'Deskripsi Baru',
                'min_purchase' => 2,
            ]);

        $product->refresh();
        $this->assertSame('Nama Baru', $product->name);
        $this->assertCount(2, $product->categories()->get());
    }

    #[Test]
    public function update_returns_404_when_product_does_not_belong_to_merchant()
    {
        $owner = $this->createUserWithRole(2);
        $merchantA = $this->createMerchantOwnedBy($owner, ['slug' => 'ma-' . uniqid()]);
        $merchantB = $this->createMerchantOwnedBy($owner, ['slug' => 'mb-' . uniqid()]);
        $productA = $this->createPublishedProductWithVariant($merchantA);
        [$cat1] = $this->createTwoCategories();

        $this->actingAs($owner)
            ->apiPut($this->merchantProductsBaseUrl($merchantB) . '/' . $productA->slug, [
                'name' => 'X',
                'description' => 'Y',
                'min_purchase' => 1,
                'category_id' => $cat1->id,
                'sku' => 'SKU-X',
                'price' => 1000,
                'stock' => 1,
            ])
            ->assertStatus(404);
    }

    #[Test]
    public function umkm_owner_can_update_product_status()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        $product = $this->createPublishedProductWithVariant($merchant, ['status' => 'published']);

        $this->actingAs($owner)
            ->apiPatch($this->merchantProductsBaseUrl($merchant) . '/' . $product->slug . '/status', [
                'status' => 'archived',
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['message' => 'Status produk diperbarui.']);

        $product->refresh();
        $this->assertSame('archived', $product->status);
    }

    #[Test]
    public function update_status_validation_fails_for_invalid_status()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        $product = $this->createPublishedProductWithVariant($merchant);

        $this->actingAs($owner)
            ->apiPatch($this->merchantProductsBaseUrl($merchant) . '/' . $product->slug . '/status', [
                'status' => 'draft',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function umkm_owner_can_destroy_product_and_removes_files()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        $product = $this->createPublishedProductWithVariant($merchant);

        $path = "products/{$product->id}/cover.jpg";
        Storage::disk('public')->put($path, 'x');

        $product->images()->create([
            'image_path' => $path,
            'display_order' => 0,
            'is_cover' => true,
        ]);

        $this->actingAs($owner)
            ->apiDelete($this->merchantProductsBaseUrl($merchant) . '/' . $product->slug)
            ->assertStatus(200)
            ->assertJsonFragment(['message' => 'Produk dihapus.']);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertFalse(Storage::disk('public')->exists($path));
    }

    #[Test]
    public function destroy_returns_404_when_product_does_not_belong_to_merchant()
    {
        $owner = $this->createUserWithRole(2);
        $merchantA = $this->createMerchantOwnedBy($owner, ['slug' => 'da-' . uniqid()]);
        $merchantB = $this->createMerchantOwnedBy($owner, ['slug' => 'db-' . uniqid()]);
        $productA = $this->createPublishedProductWithVariant($merchantA);

        $this->actingAs($owner)
            ->apiDelete($this->merchantProductsBaseUrl($merchantB) . '/' . $productA->slug)
            ->assertStatus(404);
    }

    // ======================
    // MERCHANT OWNER: BULK + EXPORT
    // ======================

    #[Test]
    public function umkm_owner_can_bulk_delete_and_reports_unauthorized_count()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner, ['slug' => 'bulk-' . uniqid()]);

        $p1 = $this->createPublishedProductWithVariant($merchant);
        $p2 = $this->createPublishedProductWithVariant($merchant);

        $otherMerchant = $this->createMerchantOwnedBy($owner, ['slug' => 'other-bulk-' . uniqid()]);
        $pOther = $this->createPublishedProductWithVariant($otherMerchant);

        $resp = $this->actingAs($owner)->apiPost($this->merchantProductsBaseUrl($merchant) . '/bulk-delete', [
            'product_slugs' => [$p1->slug, $p2->slug, $pOther->slug],
        ]);

        $resp->assertStatus(200)
            ->assertJsonPath('data.deleted_count', 2)
            ->assertJsonPath('data.unauthorized_count', 1);

        $this->assertDatabaseMissing('products', ['id' => $p1->id]);
        $this->assertDatabaseMissing('products', ['id' => $p2->id]);
        $this->assertDatabaseHas('products', ['id' => $pOther->id]);
    }

    #[Test]
    public function umkm_owner_can_bulk_update_status_and_reports_unauthorized_count()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner, ['slug' => 'bulk2-' . uniqid()]);

        $p1 = $this->createPublishedProductWithVariant($merchant, ['status' => 'draft']);
        $p2 = $this->createPublishedProductWithVariant($merchant, ['status' => 'draft']);

        $otherMerchant = $this->createMerchantOwnedBy($owner, ['slug' => 'other-bulk2-' . uniqid()]);
        $pOther = $this->createPublishedProductWithVariant($otherMerchant, ['status' => 'draft']);

        $resp = $this->actingAs($owner)->apiPost($this->merchantProductsBaseUrl($merchant) . '/bulk-update-status', [
            'product_slugs' => [$p1->slug, $p2->slug, $pOther->slug],
            'status' => 'archived',
        ]);

        $resp->assertStatus(200)
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.unauthorized_count', 1)
            ->assertJsonPath('data.new_status', 'archived');

        $p1->refresh();
        $p2->refresh();
        $pOther->refresh();
        $this->assertSame('archived', $p1->status);
        $this->assertSame('archived', $p2->status);
        $this->assertSame('draft', $pOther->status);
    }

    #[Test]
    public function export_excel_and_pdf_return_download_response()
    {
        $owner = $this->createUserWithRole(2);
        $merchant = $this->createMerchantOwnedBy($owner);
        $this->createPublishedProductWithVariant($merchant);

        $excel = $this->actingAs($owner)->apiGet($this->merchantProductsBaseUrl($merchant) . '/export/excel');
        $excel->assertStatus(200);
        $this->assertStringContainsString('attachment', (string) $excel->headers->get('content-disposition'));
        $this->assertStringContainsString('.xlsx', (string) $excel->headers->get('content-disposition'));

        $pdf = $this->actingAs($owner)->apiGet($this->merchantProductsBaseUrl($merchant) . '/export/pdf');
        $pdf->assertStatus(200);
        $this->assertStringContainsString('attachment', (string) $pdf->headers->get('content-disposition'));
        $this->assertStringContainsString('.pdf', (string) $pdf->headers->get('content-disposition'));
    }

    // ======================
    // ADMIN ENDPOINTS
    // ======================

    #[Test]
    public function guest_cannot_access_admin_products_endpoints()
    {
        $merchant = Merchant::factory()->approved()->create();
        $product = $this->createPublishedProductWithVariant($merchant);

        $this->apiGet('/api/admin/products')->assertStatus(401);
        $this->apiDelete('/api/admin/products/' . $product->id)->assertStatus(401);
    }

    #[Test]
    public function non_admin_cannot_access_admin_products_endpoints()
    {
        $owner = $this->createUserWithRole(2);
        $this->actingAs($owner)
            ->apiGet('/api/admin/products')
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Forbidden.']);
    }

    #[Test]
    public function admin_can_list_products_with_meta()
    {
        $admin = $this->createUserWithRole(1);
        $merchant = Merchant::factory()->approved()->create();
        $this->createPublishedProductWithVariant($merchant);

        $this->actingAs($admin)
            ->apiGet('/api/admin/products', ['per_page' => 10])
            ->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => ['id', 'merchant_id', 'name', 'slug', 'status', 'min_purchase', 'created_at', 'merchant'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    #[Test]
    public function admin_can_delete_product_and_cleans_up_images()
    {
        $admin = $this->createUserWithRole(1);
        $merchant = Merchant::factory()->approved()->create();
        $product = $this->createPublishedProductWithVariant($merchant);

        $path = "products/{$product->id}/x.jpg";
        Storage::disk('public')->put($path, 'x');
        /** @var Image $img */
        $img = $product->images()->create([
            'image_path' => $path,
            'display_order' => 0,
            'is_cover' => true,
        ]);

        $this->actingAs($admin)
            ->apiDelete('/api/admin/products/' . $product->id)
            ->assertStatus(200)
            ->assertJsonFragment(['message' => 'Produk dihapus.']);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('images', ['id' => $img->id]);
        $this->assertFalse(Storage::disk('public')->exists($path));
    }
}
