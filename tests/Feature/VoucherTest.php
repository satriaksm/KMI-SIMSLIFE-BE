<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Merchant;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;


class VoucherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->seedSegmentations();
    }

    // ======================
    // MERCHANT AUTH SETUP
    // ======================

    protected function createMerchantUser()
    {
        $user = User::factory()->create();
        $roleId = 2;
        $user->roles()->syncWithoutDetaching([$roleId]);

        // pastikan FK ada
        $paguyubanId = DB::table('paguyubans')->insertGetId([
            'name' => 'Test Paguyuban',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $segmentationId = DB::table('segmentations')->insertGetId([
            'name' => 'UMKM Toko',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'paguyuban_id' => $paguyubanId,
            'segmentation_id' => $segmentationId,
        ]);

        return [$user, $merchant];
    }

    // ======================
    // CUSTOMER VOUCHER TESTS
    // ======================

    #[Test]
    public function customer_can_view_vouchers_by_merchant()
    {
        $user = User::factory()->create();
        $roleId = 3;
        $user->roles()->attach($roleId);

        $paguyubanId = DB::table('paguyubans')->insertGetId([
            'name' => 'Test Paguyuban',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $segmentationId = DB::table('segmentations')->insertGetId([
            'name' => 'UMKM Toko',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $merchant = Merchant::factory()->create([
            'paguyuban_id' => $paguyubanId,
            'segmentation_id' => $segmentationId,
        ]);

        Voucher::factory()->count(3)->create([
            'merchant_id' => $merchant->id,
            'voucher_status' => 'active',
            'voucher_end_date' => now()->addDays(5),
        ]);

        $this->actingAs($user);

        $response = $this->apiGet("api/checkout/{$merchant->slug}/vouchers");

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Daftar voucher tersedia']);
    }

    #[Test]
    public function customer_cannot_access_without_auth()
    {
        $paguyubanId = DB::table('paguyubans')->insertGetId([
            'name' => 'Test Paguyuban',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $segmentationId = DB::table('segmentations')->insertGetId([
            'name' => 'UMKM Toko',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $merchant = Merchant::factory()->create([
            'paguyuban_id' => $paguyubanId,
            'segmentation_id' => $segmentationId,
        ]);

        $response = $this->apiGet("api/checkout/{$merchant->slug}/vouchers");

        $response->assertStatus(401);
    }


    // ====================== //
    // MERCHANT CRUD TESTS
    // ====================== //

    // ======================
    // VALIDATION TESTS
    // ======================
    #[Test]
    public function merchant_create_voucher_validation_errors()
    {
        [$user, $merchant] = $this->createMerchantUser();
        $this->actingAs($user);

        // Empty payload
        $response = $this->apiPost("api/merchant/{$merchant->slug}/vouchers", []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'voucher_name',
                'voucher_code',
                'voucher_type',
                'value',
                'voucher_start_date',
                // 'voucher_end_date',
                'usage_limit_per_user',
            ]);

        // Invalid types and values
        $payload = [
            'voucher_name' => str_repeat('a', 101),
            'voucher_code' => '', // required
            'voucher_type' => 'invalid',
            'value' => -1,
            'voucher_start_date' => 'not-a-date',
            'voucher_end_date' => '2020-01-01',
            'usage_limit_per_user' => 0,
            'usage_limit' => 0,
        ];
        $response = $this->apiPost("api/merchant/{$merchant->slug}/vouchers", $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'voucher_name',
                'voucher_code',
                'voucher_type',
                'value',
                'voucher_start_date',
                // 'voucher_end_date',
                'usage_limit_per_user',
                // usage_limit is nullable, so not always error
            ]);
    }

    #[Test]
    public function merchant_update_voucher_validation_errors()
    {
        [$user, $merchant] = $this->createMerchantUser();
        $this->actingAs($user);

        $voucher = Voucher::factory()->create([
            'merchant_id' => $merchant->id
        ]);

        // Empty payload
        $response = $this->apiPut("api/merchant/{$merchant->slug}/vouchers/{$voucher->id}", []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'voucher_name',
                'voucher_code',
                'voucher_description',
                'voucher_type',
                'value',
                'voucher_start_date',
                // 'voucher_end_date',
                'min_purchase_amount',
                'usage_limit_per_user',
                'usage_limit',
            ]);

        // Invalid types and values
        $payload = [
            'voucher_name' => str_repeat('a', 256),
            'voucher_code' => '',
            'voucher_description' => '',
            'voucher_type' => 'invalid',
            'value' => 0,
            'voucher_start_date' => 'not-a-date',
            'voucher_end_date' => '2020-01-01',
            'min_purchase_amount' => -1,
            'max_discount_amount' => -1,
            'usage_limit_per_user' => 0,
            'usage_limit' => -1,
        ];
        $response = $this->apiPut("api/merchant/{$merchant->slug}/vouchers/{$voucher->id}", $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'voucher_name',
                'voucher_code',
                'voucher_description',
                'voucher_type',
                'value',
                'voucher_start_date',
                // 'voucher_end_date',
                'min_purchase_amount',
                'max_discount_amount',
                'usage_limit_per_user',
                'usage_limit',
            ]);
    }

    #[Test]
    public function merchant_create_voucher_end_date_must_be_after_start_date()
    {
        [$user, $merchant] = $this->createMerchantUser();
        $this->actingAs($user);

        $payload = [
            'voucher_name' => 'Test',
            'voucher_code' => 'TEST123',
            'voucher_type' => 'percent',
            'value' => 10,
            'voucher_start_date' => '2026-02-01',
            'voucher_end_date' => '2026-01-01',
            'usage_limit_per_user' => 1,
        ];

        $response = $this->apiPost("api/merchant/{$merchant->slug}/vouchers", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['voucher_end_date']);
    }
    #[Test]
    public function merchant_update_voucher_end_date_must_be_after_start_date()
    {
        [$user, $merchant] = $this->createMerchantUser();
        $this->actingAs($user);

        $voucher = Voucher::factory()->create([
            'merchant_id' => $merchant->id
        ]);

        $payload = [
            'voucher_name' => 'Test',
            'voucher_code' => 'TEST123',
            'voucher_type' => 'percent',
            'value' => 10,
            'voucher_start_date' => '2026-02-01',
            'voucher_end_date' => '2026-01-01',
            'usage_limit_per_user' => 1,
        ];


        $response = $this->apiPut("api/merchant/{$merchant->slug}/vouchers/{$voucher->id}", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['voucher_end_date']);
    }


    // ======================
    // FUNCTIONALITY TESTS
    // ======================
    #[Test]
    public function merchant_can_create_voucher()
    {
        [$user, $merchant] = $this->createMerchantUser();
        $this->actingAs($user);

        $payload = [
            'voucher_name' => 'Diskon 10%',
            'voucher_code' => 'DISC10',
            'voucher_type' => 'percent',
            'value' => 10,
            'voucher_start_date' => now()->toDateString(),
            'voucher_end_date' => now()->addDays(10)->toDateString(),
            'usage_limit_per_user' => 1,
            'usage_limit' => 100,
        ];

        $response = $this->apiPost("api/merchant/{$merchant->slug}/vouchers", $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('vouchers', [
            'voucher_code' => 'DISC10',
            'merchant_id' => $merchant->id,
        ]);
    }

    #[Test]
    public function merchant_can_list_vouchers()
    {
        [$user, $merchant] = $this->createMerchantUser();
        $this->actingAs($user);

        Voucher::factory()->count(3)->create([
            'merchant_id' => $merchant->id
        ]);

        $response = $this->apiGet("api/merchant/{$merchant->slug}/vouchers");

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Daftar voucher tersedia']);
    }

    #[Test]
    public function merchant_can_view_single_voucher()
    {
        [$user, $merchant] = $this->createMerchantUser();
        $this->actingAs($user);

        $voucher = Voucher::factory()->create([
            'merchant_id' => $merchant->id
        ]);

        $response = $this->apiGet("api/merchant/{$merchant->slug}/vouchers/{$voucher->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $voucher->id]);
    }

    #[Test]
    public function merchant_can_update_voucher()
    {
        [$user, $merchant] = $this->createMerchantUser();
        $this->actingAs($user);

        $voucher = Voucher::factory()->create([
            'merchant_id' => $merchant->id
        ]);

        $payload = [
            'voucher_name' => 'Updated Voucher',
            'voucher_code' => $voucher->voucher_code,
            'voucher_description' => 'Updated',
            'voucher_type' => 'fixed',
            'value' => 5000,
            'voucher_start_date' => now(),
            'voucher_end_date' => now()->addDays(5),
            'min_purchase_amount' => 0,
            'max_discount_amount' => null,
            'usage_limit_per_user' => 1,
            'usage_limit' => 10,
        ];

        $response = $this->apiPut("api/merchant/{$merchant->slug}/vouchers/{$voucher->id}", $payload);

        $response->assertStatus(200);

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->id,
            'voucher_name' => 'Updated Voucher'
        ]);
    }

    #[Test]
    public function merchant_can_delete_voucher()
    {
        [$user, $merchant] = $this->createMerchantUser();
        $this->actingAs($user);

        $voucher = Voucher::factory()->create([
            'merchant_id' => $merchant->id
        ]);

        $response = $this->apiDelete("api/merchant/{$merchant->slug}/vouchers/{$voucher->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('vouchers', [
            'id' => $voucher->id
        ]);
    }

    #[Test]
    public function merchant_can_update_voucher_status()
    {
        [$user, $merchant] = $this->createMerchantUser();
        $this->actingAs($user);

        $voucher = Voucher::factory()->create([
            'merchant_id' => $merchant->id,
            'voucher_status' => 'active'
        ]);

        $response = $this->apiPatch("api/merchant/{$merchant->slug}/vouchers/{$voucher->id}/status", [
            'voucher_status' => 'inactive'
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->id,
            'voucher_status' => 'inactive'
        ]);
    }

    #[Test]
    public function merchant_can_bulk_delete_vouchers()
    {
        [$user, $merchant] = $this->createMerchantUser();
        $this->actingAs($user);

        $vouchers = Voucher::factory()->count(3)->create([
            'merchant_id' => $merchant->id
        ]);

        $response = $this->apiPost("api/merchant/{$merchant->slug}/vouchers/bulk-delete", [
            'voucher_ids' => $vouchers->pluck('id')->toArray()
        ]);

        $response->assertStatus(200);
    }

    #[Test]
    public function merchant_can_bulk_update_status()
    {
        [$user, $merchant] = $this->createMerchantUser();
        $this->actingAs($user);

        $vouchers = Voucher::factory()->count(2)->create([
            'merchant_id' => $merchant->id,
            'voucher_status' => 'active'
        ]);

        $response = $this->apiPost("api/merchant/{$merchant->slug}/vouchers/bulk-update-status", [
            'voucher_ids' => $vouchers->pluck('id')->toArray(),
            'voucher_status' => 'inactive'
        ]);

        $response->assertStatus(200);
    }
}
