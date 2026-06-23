<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Addon;
use App\Models\AddonGroup;
use App\Models\AddonGroupOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class CartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedRoles();
        $this->seedSegmentations();
    }

    protected function createCustomerUser(bool $verified = true): User
    {
        $user = User::factory()->create([
            'email_verified_at' => $verified ? now() : null,
        ]);

        // role: customer
        $user->roles()->syncWithoutDetaching([3]);

        return $user;
    }

    protected function createUmkmOwnerUser(bool $verified = true): User
    {
        $user = User::factory()->create([
            'email_verified_at' => $verified ? now() : null,
        ]);

        // role: umkm-owner
        $user->roles()->syncWithoutDetaching([2]);

        return $user;
    }

    protected function createMerchant(): Merchant
    {
        return Merchant::factory()->create([
            'segmentation_id' => 1,
            'status' => 'approved',
        ]);
    }

    /**
     * @return array{0: Product, 1: ProductVariant}
     */
    protected function createProductWithVariant(Merchant $merchant, int $stock = 10, int $price = 15000): array
    {
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => 'published',
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => $price,
            'stock' => $stock,
        ]);

        return [$product, $variant];
    }

    /**
     * Create one addon group + options.
     *
     * @param array<int, array{name: string, price: int}> $addons
     * @return array{group: AddonGroup, addons: array<int, Addon>, options: array<int, AddonGroupOption>}
     */
    protected function createAddonGroupWithOptions(Merchant $merchant, Product $product, array $addons): array
    {
        $group = AddonGroup::query()->create([
            'product_id' => $product->id,
            'addon_group_name' => 'Topping',
            'selection_type' => 'multiple',
            'min_selection' => 0,
            'max_selection' => null,
        ]);

        $addonModels = [];
        $optionModels = [];

        foreach ($addons as $a) {
            $addon = Addon::query()->create([
                'merchant_id' => $merchant->id,
                'addon_name' => $a['name'],
            ]);

            $option = AddonGroupOption::query()->create([
                'addon_group_id' => $group->id,
                'addon_id' => $addon->id,
                'addon_price' => $a['price'],
            ]);

            $addonModels[] = $addon;
            $optionModels[] = $option;
        }

        return ['group' => $group, 'addons' => $addonModels, 'options' => $optionModels];
    }

    // ======================
    // AUTH / ACCESS
    // ======================

    #[Test]
    public function guest_cannot_access_cart_endpoints()
    {
        $this->apiGet('/api/cart')->assertStatus(401);
        $this->apiGet('/api/cart/count')->assertStatus(401);
    }

    #[Test]
    public function non_customer_cannot_access_cart_endpoints()
    {
        $user = $this->createUmkmOwnerUser();

        $this->actingAs($user)
            ->apiGet('/api/cart')
            ->assertStatus(403);
    }

    #[Test]
    public function unverified_customer_cannot_access_cart_endpoints()
    {
        $user = $this->createCustomerUser(verified: false);

        $this->actingAs($user)
            ->apiGet('/api/cart')
            ->assertStatus(403);
    }

    // ======================
    // ADD TO CART
    // ======================

    #[Test]
    public function customer_can_add_item_to_cart_creates_cart_and_cart_item()
    {
        $user = $this->createCustomerUser();
        $merchant = $this->createMerchant();
        [$product, $variant] = $this->createProductWithVariant($merchant, stock: 10, price: 12000);

        $response = $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => ['cart_item_id'],
            ]);

        $this->assertDatabaseHas('carts', [
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
        ]);

        $this->assertDatabaseHas('cart_items', [
            'itemable_id' => $product->id,
            'itemable_type' => Product::class,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]);
    }

    #[Test]
    public function adding_same_configuration_increments_quantity_instead_of_creating_duplicate_item()
    {
        $user = $this->createCustomerUser();
        $merchant = $this->createMerchant();
        [$product, $variant] = $this->createProductWithVariant($merchant, stock: 20);

        $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertStatus(200);

        $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 3,
        ])->assertStatus(200)
            ->assertJsonFragment(['message' => 'Cart item quantity updated']);

        $cart = Cart::where('user_id', $user->id)->where('merchant_id', $merchant->id)->firstOrFail();
        $this->assertEquals(1, $cart->items()->count());
        $this->assertEquals(5, (int) $cart->items()->value('quantity'));
    }

    #[Test]
    public function add_to_cart_fails_when_stock_insufficient()
    {
        $user = $this->createCustomerUser();
        $merchant = $this->createMerchant();
        [$product, $variant] = $this->createProductWithVariant($merchant, stock: 4);

        $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 3,
        ])->assertStatus(200);

        $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Stok tidak mencukupi']);
    }

    #[Test]
    public function customer_can_add_item_with_addons_and_addon_snapshots_are_saved()
    {
        $user = $this->createCustomerUser();
        $merchant = $this->createMerchant();
        [$product, $variant] = $this->createProductWithVariant($merchant, stock: 10);

        $addonSetup = $this->createAddonGroupWithOptions($merchant, $product, [
            ['name' => 'Extra Cheese', 'price' => 2500],
        ]);

        $group = $addonSetup['group'];
        $addon = $addonSetup['addons'][0];

        $response = $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
            'addons' => [
                ['group_id' => $group->id, 'addon_id' => $addon->id],
            ],
        ]);

        $response->assertStatus(200);

        $cartItemId = (int) ($response->json('data.cart_item_id') ?? 0);
        $this->assertGreaterThan(0, $cartItemId);

        $this->assertDatabaseHas('cart_item_addons', [
            'cart_item_id' => $cartItemId,
            'addon_group_id' => $group->id,
            'addon_id' => $addon->id,
            'addon_name_snapshot' => 'Extra Cheese',
            'addon_price_snapshot' => 2500,
        ]);
    }

    // ======================
    // INDEX + COUNT
    // ======================

    #[Test]
    public function cart_count_returns_total_quantity_across_all_carts_for_user()
    {
        $user = $this->createCustomerUser();

        $merchant1 = $this->createMerchant();
        [$p1, $v1] = $this->createProductWithVariant($merchant1, stock: 50);

        $merchant2 = $this->createMerchant();
        [$p2, $v2] = $this->createProductWithVariant($merchant2, stock: 50);

        $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $p1->id,
            'variant_id' => $v1->id,
            'quantity' => 2,
        ])->assertStatus(200);

        $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $p2->id,
            'variant_id' => $v2->id,
            'quantity' => 3,
        ])->assertStatus(200);

        $this->actingAs($user)
            ->apiGet('/api/cart/count')
            ->assertStatus(200)
            ->assertJsonPath('data.count', 5);
    }

    #[Test]
    public function cart_index_returns_expected_structure()
    {
        $user = $this->createCustomerUser();
        $merchant = $this->createMerchant();
        [$product, $variant] = $this->createProductWithVariant($merchant, stock: 10, price: 10000);

        $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertStatus(200);

        $this->actingAs($user)
            ->apiGet('/api/cart')
            ->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => [
                        'cart_id',
                        'merchant' => ['id', 'slug', 'name', 'phone', 'address', 'logo'],
                        'items' => [
                            '*' => [
                                'cart_item_id',
                                'quantity',
                                'snapshot' => ['name', 'variant_label', 'image', 'unit_price', 'addons', 'addon_total_price'],
                                'live' => ['unit_price', 'max_stock', 'is_available'],
                                'changes' => ['price_changed', 'is_over_stock'],
                                'selected_configuration' => ['product_id', 'variant_id', 'addon_ids'],
                                'product_details',
                            ],
                        ],
                    ],
                ],
            ]);
    }

    // ======================
    // UPDATE QUANTITY
    // ======================

    #[Test]
    public function customer_can_update_quantity_within_stock()
    {
        $user = $this->createCustomerUser();
        $merchant = $this->createMerchant();
        [$product, $variant] = $this->createProductWithVariant($merchant, stock: 10);

        $resp = $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $cartItemId = (int) $resp->json('data.cart_item_id');

        $this->actingAs($user)
            ->apiPatch("/api/cart/items/{$cartItemId}", ['quantity' => 4])
            ->assertStatus(200)
            ->assertJsonFragment([
                'message' => 'Quantity updated',
                'cart_item_id' => $cartItemId,
                'quantity' => 4,
            ]);

        $this->assertDatabaseHas('cart_items', [
            'id' => $cartItemId,
            'quantity' => 4,
        ]);
    }

    #[Test]
    public function update_quantity_fails_when_total_cart_quantity_for_variant_exceeds_stock()
    {
        $user = $this->createCustomerUser();
        $merchant = $this->createMerchant();
        [$product, $variant] = $this->createProductWithVariant($merchant, stock: 5);

        $addonSetup = $this->createAddonGroupWithOptions($merchant, $product, [
            ['name' => 'Addon A', 'price' => 1000],
            ['name' => 'Addon B', 'price' => 2000],
        ]);
        $group = $addonSetup['group'];
        [$addonA, $addonB] = $addonSetup['addons'];

        // Create 2 different cart items with same variant by using different addon sets
        $r1 = $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 3,
            'addons' => [
                ['group_id' => $group->id, 'addon_id' => $addonA->id],
            ],
        ]);
        $item1 = (int) $r1->json('data.cart_item_id');

        $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'addons' => [
                ['group_id' => $group->id, 'addon_id' => $addonB->id],
            ],
        ])->assertStatus(200);

        // Now updating item1 to 4 makes total = 4 + 2 = 6 > 5
        $this->actingAs($user)
            ->apiPatch("/api/cart/items/{$item1}", ['quantity' => 4])
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Stok tidak mencukupi. Total di keranjang akan melebihi stok tersedia.',
            ]);
    }

    // ======================
    // REMOVE + CLEAR
    // ======================

    #[Test]
    public function removing_last_item_deletes_cart()
    {
        $user = $this->createCustomerUser();
        $merchant = $this->createMerchant();
        [$product, $variant] = $this->createProductWithVariant($merchant, stock: 10);

        $resp = $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);
        $cartItemId = (int) $resp->json('data.cart_item_id');

        $cartId = (int) CartItem::findOrFail($cartItemId)->cart_id;

        $this->actingAs($user)
            ->apiDelete("/api/cart/items/{$cartItemId}")
            ->assertStatus(200)
            ->assertJsonPath('data.cart_deleted', true);

        $this->assertDatabaseMissing('cart_items', ['id' => $cartItemId]);
        $this->assertDatabaseMissing('carts', ['id' => $cartId]);
    }

    #[Test]
    public function customer_can_clear_cart()
    {
        $user = $this->createCustomerUser();
        $merchant = $this->createMerchant();
        [$product, $variant] = $this->createProductWithVariant($merchant, stock: 50);

        $r1 = $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $cartItemId = (int) $r1->json('data.cart_item_id');
        $cartId = (int) CartItem::findOrFail($cartItemId)->cart_id;

        $this->actingAs($user)
            ->apiDelete("/api/cart/{$cartId}")
            ->assertStatus(200)
            ->assertJsonFragment(['message' => 'Cart berhasil dikosongkan']);

        $this->assertDatabaseMissing('cart_items', ['cart_id' => $cartId]);
        $this->assertDatabaseMissing('carts', ['id' => $cartId]);
    }

    // ======================
    // UPDATE VARIANT
    // ======================

    #[Test]
    public function update_variant_validation_errors()
    {
        $user = $this->createCustomerUser();
        $merchant = $this->createMerchant();
        [$product, $variant] = $this->createProductWithVariant($merchant, stock: 10);

        $resp = $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
        ]);
        $cartItemId = (int) $resp->json('data.cart_item_id');

        $this->actingAs($user)
            ->apiPatch("/api/cart/items/{$cartItemId}/variant", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_variant_id']);
    }

    #[Test]
    public function update_variant_fails_when_variant_stock_is_not_enough_for_current_quantity()
    {
        $user = $this->createCustomerUser();
        $merchant = $this->createMerchant();
        [$product, $variantA] = $this->createProductWithVariant($merchant, stock: 50);
        $variantB = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'stock' => 1,
            'price' => 20000,
        ]);

        $resp = $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variantA->id,
            'quantity' => 2,
        ]);
        $cartItemId = (int) $resp->json('data.cart_item_id');

        $this->actingAs($user)
            ->apiPatch("/api/cart/items/{$cartItemId}/variant", [
                'product_variant_id' => $variantB->id,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Stok varian tidak mencukupi']);
    }

    #[Test]
    public function update_variant_merges_duplicate_items_with_same_variant_and_addons()
    {
        $user = $this->createCustomerUser();
        $merchant = $this->createMerchant();
        [$product, $variantA] = $this->createProductWithVariant($merchant, stock: 50);
        $variantB = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'stock' => 50,
            'price' => 22000,
        ]);

        $addonSetup = $this->createAddonGroupWithOptions($merchant, $product, [
            ['name' => 'Addon X', 'price' => 1000],
        ]);
        $group = $addonSetup['group'];
        $addonX = $addonSetup['addons'][0];

        // Item 1: variant A + addon X (qty 2)
        $r1 = $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variantA->id,
            'quantity' => 2,
            'addons' => [
                ['group_id' => $group->id, 'addon_id' => $addonX->id],
            ],
        ]);
        $item1 = (int) $r1->json('data.cart_item_id');

        // Item 2: variant B + addon X (qty 1)
        $r2 = $this->actingAs($user)->apiPost('/api/cart/items', [
            'product_id' => $product->id,
            'variant_id' => $variantB->id,
            'quantity' => 1,
            'addons' => [
                ['group_id' => $group->id, 'addon_id' => $addonX->id],
            ],
        ]);
        $item2 = (int) $r2->json('data.cart_item_id');

        // Update item2 variant -> A with same addon set => should merge into item1
        $resp = $this->actingAs($user)->apiPatch("/api/cart/items/{$item2}/variant", [
            'product_variant_id' => $variantA->id,
            'addons' => [
                ['addon_group_id' => $group->id, 'addon_id' => $addonX->id],
            ],
        ]);

        $resp->assertStatus(200)
            ->assertJsonFragment(['message' => 'Item digabung'])
            ->assertJsonFragment(['cart_item_id' => $item1]);

        $this->assertDatabaseMissing('cart_items', ['id' => $item2]);
        $this->assertDatabaseHas('cart_items', [
            'id' => $item1,
            'product_variant_id' => $variantA->id,
            'quantity' => 3,
        ]);
    }
}
