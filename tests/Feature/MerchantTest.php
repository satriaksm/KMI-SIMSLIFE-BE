<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Province;
use App\Models\City;
use App\Models\District;
use App\Models\Village;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MerchantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedRoles();
        $this->seedSegmentations();
    }

    protected function createVerifiedUserWithRole(int $roleId): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $user->roles()->syncWithoutDetaching([$roleId]);

        return $user;
    }

    /**
     * @return array{province: Province, city: City, district: District, village: Village}
     */
    protected function createLocationSet(): array
    {
        $village = Village::factory()->create();
        $district = District::query()->findOrFail($village->district_id);
        $city = City::query()->findOrFail($district->city_id);
        $province = Province::query()->findOrFail($city->province_id);

        return [
            'province' => $province,
            'city' => $city,
            'district' => $district,
            'village' => $village,
        ];
    }

    protected function addPrimaryAddressToMerchant(Merchant $merchant, ?float $lat = -7.1, ?float $lng = 110.2): void
    {
        $loc = $this->createLocationSet();

        $merchant->addresses()->create([
            'province_id' => $loc['province']->id,
            'city_id' => $loc['city']->id,
            'district_id' => $loc['district']->id,
            'village_id' => $loc['village']->id,
            'detail' => 'Jl. Utama No. 1',
            'label' => 'utama',
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }

    // ======================
    // PUBLIC ENDPOINTS
    // ======================

    #[Test]
    public function public_map_returns_only_approved_merchants()
    {
        $approved = Merchant::factory()->approved()->create([
            'status' => 'approved',
        ]);
        $pending = Merchant::factory()->pending()->create([
            'status' => 'pending',
        ]);

        $this->addPrimaryAddressToMerchant($approved);
        $this->addPrimaryAddressToMerchant($pending);

        $response = $this->getJson('/api/public/merchants/map');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'logo_url',
                        'latitude',
                        'longitude',
                        'segmentation',
                    ],
                ],
            ]);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($approved->id, $ids);
        $this->assertNotContains($pending->id, $ids);
    }

    #[Test]
    public function public_show_returns_merchant_by_slug_with_lat_lng()
    {
        $merchant = Merchant::factory()->approved()->create([
            'status' => 'approved',
        ]);
        $this->addPrimaryAddressToMerchant($merchant, lat: -7.123456, lng: 110.654321);

        $response = $this->getJson('/api/public/merchants/' . $merchant->slug);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'segmentation',
                    'primary_address',
                    'latitude',
                    'longitude',
                ],
            ]);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('status', $data);
        $this->assertNotNull($data['latitude']);
        $this->assertNotNull($data['longitude']);
    }

    #[Test]
    public function public_show_can_find_merchant_by_id_param()
    {
        $merchant = Merchant::factory()->approved()->create([
            'status' => 'approved',
        ]);
        $this->addPrimaryAddressToMerchant($merchant);

        $this->getJson('/api/public/merchants/' . $merchant->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $merchant->id);
    }

    #[Test]
    public function public_show_returns_404_for_non_approved_merchant()
    {
        $merchant = Merchant::factory()->pending()->create([
            'status' => 'pending',
        ]);

        $this->getJson('/api/public/merchants/' . $merchant->slug)
            ->assertStatus(404);
    }

    // ======================
    // CUSTOMER: REGISTER MERCHANT
    // ======================

    #[Test]
    public function guest_cannot_register_merchant()
    {
        $this->apiPost('/api/merchant-register', [])
            ->assertStatus(401);
    }

    #[Test]
    public function non_customer_cannot_register_merchant()
    {
        $user = $this->createVerifiedUserWithRole(2); // umkm-owner
        $loc = $this->createLocationSet();

        $this->actingAs($user)
            ->apiPost('/api/merchant-register', [
                'name' => 'Test UMKM',
                'phone' => '+62812345678',
                'segmentation_id' => 1,
                'address' => [
                    'province_id' => $loc['province']->id,
                    'city_id' => $loc['city']->id,
                    'district_id' => $loc['district']->id,
                    'village_id' => $loc['village']->id,
                    'detail' => 'Alamat',
                    'latitude' => -7.1,
                    'longitude' => 110.2,
                ],
            ])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Forbidden.']);
    }

    #[Test]
    public function customer_register_validation_error_returns_422()
    {
        $user = $this->createVerifiedUserWithRole(3); // customer

        $this->actingAs($user)
            ->apiPost('/api/merchant-register', [])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Data yang diberikan tidak valid.'])
            ->assertJsonStructure(['message', 'errors']);
    }

    #[Test]
    public function customer_can_register_merchant_and_creates_primary_address()
    {
        $user = $this->createVerifiedUserWithRole(3); // customer
        $loc = $this->createLocationSet();

        $response = $this->actingAs($user)
            ->apiPost('/api/merchant-register', [
                'name' => 'UMKM Baru',
                'phone' => '+628123456789',
                'description' => 'Desc',
                'segmentation_id' => 1,
                'address' => [
                    'province_id' => $loc['province']->id,
                    'city_id' => $loc['city']->id,
                    'district_id' => $loc['district']->id,
                    'village_id' => $loc['village']->id,
                    'detail' => 'Alamat detail',
                    'latitude' => -7.2,
                    'longitude' => 110.3,
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'merchant' => [
                        'id',
                        'user_id',
                        'segmentation_id',
                        'name',
                        'slug',
                    ],
                ],
            ]);

        $merchantId = (int) $response->json('data.merchant.id');
        $this->assertDatabaseHas('merchants', [
            'id' => $merchantId,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $merchantMorph = (new Merchant())->getMorphClass();
        $this->assertDatabaseHas('addresses', [
            'addressable_id' => $merchantId,
            'addressable_type' => $merchantMorph,
            'label' => 'utama',
        ]);
    }

    #[Test]
    public function customer_cannot_register_second_pending_merchant()
    {
        $user = $this->createVerifiedUserWithRole(3); // customer
        $loc = $this->createLocationSet();

        $pending = Merchant::factory()->pending()->create([
            'user_id' => $user->id,
        ]);
        $this->addPrimaryAddressToMerchant($pending);

        $this->actingAs($user)
            ->apiPost('/api/merchant-register', [
                'name' => 'UMKM Baru',
                'phone' => '+628123456789',
                'segmentation_id' => 1,
                'address' => [
                    'province_id' => $loc['province']->id,
                    'city_id' => $loc['city']->id,
                    'district_id' => $loc['district']->id,
                    'village_id' => $loc['village']->id,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Anda sudah memiliki pendaftaran UMKM yang menunggu.']);
    }

    // ======================
    // UMKM OWNER: MANAGE OWN MERCHANT
    // ======================

    #[Test]
    public function umkm_owner_can_view_own_approved_merchant_profile()
    {
        $owner = $this->createVerifiedUserWithRole(2);
        $merchant = Merchant::factory()->approved()->create([
            'user_id' => $owner->id,
            'status' => 'approved',
        ]);
        $this->addPrimaryAddressToMerchant($merchant);

        $this->actingAs($owner)
            ->apiGet('/api/merchant/' . $merchant->slug . '/profile')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $merchant->id);
    }

    #[Test]
    public function umkm_owner_cannot_view_profile_if_merchant_pending()
    {
        $owner = $this->createVerifiedUserWithRole(2);
        $merchant = Merchant::factory()->pending()->create([
            'user_id' => $owner->id,
            'status' => 'pending',
        ]);

        $this->actingAs($owner)
            ->apiGet('/api/merchant/' . $merchant->slug . '/profile')
            ->assertStatus(403);
    }

    #[Test]
    public function umkm_owner_cannot_view_other_users_merchant_profile()
    {
        $owner = $this->createVerifiedUserWithRole(2);
        $other = $this->createVerifiedUserWithRole(2);
        $merchant = Merchant::factory()->approved()->create([
            'user_id' => $owner->id,
            'status' => 'approved',
        ]);

        $this->actingAs($other)
            ->apiGet('/api/merchant/' . $merchant->slug . '/profile')
            ->assertStatus(403);
    }

    #[Test]
    public function customer_role_cannot_access_umkm_owner_routes()
    {
        $customer = $this->createVerifiedUserWithRole(3);
        $merchant = Merchant::factory()->approved()->create([
            'status' => 'approved',
        ]);

        $this->actingAs($customer)
            ->apiGet('/api/merchant/' . $merchant->slug . '/profile')
            ->assertStatus(403);
    }

    #[Test]
    public function umkm_owner_can_update_own_approved_merchant_profile()
    {
        $owner = $this->createVerifiedUserWithRole(2);
        $merchant = Merchant::factory()->approved()->create([
            'user_id' => $owner->id,
            'status' => 'approved',
        ]);

        // Ensure merchant has an initial address row (legacy data without address should not crash either,
        // but this keeps the test focused on update success path).
        $this->addPrimaryAddressToMerchant($merchant);

        $this->actingAs($owner)
            ->apiPost('/api/merchant/' . $merchant->slug . '/update', [
                'name' => 'Nama Baru',
                'phone' => '08123456789',
                'description' => 'Desc baru',
                'bank_code' => '008',
                'bank_account_number' => '1234567890',
                'bank_account_name' => 'John Doe',
                'operational_hours' => json_encode([
                    'monday' => ['is_open' => true, 'open' => '08:00', 'close' => '17:00'],
                ]),
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['message' => 'Profil UMKM berhasil diperbarui.']);

        $this->assertDatabaseHas('merchants', [
            'id' => $merchant->id,
            'name' => 'Nama Baru',
        ]);
    }

    #[Test]
    public function umkm_owner_cannot_update_profile_with_invalid_operational_hours()
    {
        $owner = $this->createVerifiedUserWithRole(2);
        $merchant = Merchant::factory()->approved()->create([
            'user_id' => $owner->id,
            'status' => 'approved',
        ]);

        $this->addPrimaryAddressToMerchant($merchant);

        $this->actingAs($owner)
            ->apiPost('/api/merchant/' . $merchant->slug . '/update', [
                'name' => 'Nama Baru',
                'phone' => '08123456789',
                'description' => 'Desc baru',
                'bank_code' => '008',
                'bank_account_number' => '1234567890',
                'bank_account_name' => 'John Doe',
                'operational_hours' => json_encode([
                    'monday' => ['is_open' => true, 'open' => '17:00', 'close' => '08:00'],
                ]),
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Jam tutup harus setelah jam buka untuk hari Senin.']);
    }

    #[Test]
    public function umkm_owner_update_requires_name()
    {
        $owner = $this->createVerifiedUserWithRole(2);
        $merchant = Merchant::factory()->approved()->create([
            'user_id' => $owner->id,
            'status' => 'approved',
        ]);

        $this->actingAs($owner)
            ->apiPost('/api/merchant/' . $merchant->slug . '/update', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function umkm_owner_cannot_update_if_merchant_pending()
    {
        $owner = $this->createVerifiedUserWithRole(2);
        $merchant = Merchant::factory()->pending()->create([
            'user_id' => $owner->id,
            'status' => 'pending',
        ]);

        $this->actingAs($owner)
            ->apiPost('/api/merchant/' . $merchant->slug . '/update', [
                'name' => 'Nama Baru',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function umkm_owner_can_delete_own_approved_merchant()
    {
        $owner = $this->createVerifiedUserWithRole(2);
        $merchant = Merchant::factory()->approved()->create([
            'user_id' => $owner->id,
            'status' => 'approved',
        ]);
        $this->addPrimaryAddressToMerchant($merchant);

        $this->actingAs($owner)
            ->apiDelete('/api/merchant/' . $merchant->slug)
            ->assertStatus(200)
            ->assertJsonFragment(['message' => 'UMKM berhasil dihapus.']);

        $this->assertDatabaseMissing('merchants', ['id' => $merchant->id]);
        $this->assertDatabaseMissing('addresses', [
            'addressable_id' => $merchant->id,
            'addressable_type' => Merchant::class,
        ]);
    }

    #[Test]
    public function umkm_owner_cannot_delete_other_users_merchant()
    {
        $owner = $this->createVerifiedUserWithRole(2);
        $other = $this->createVerifiedUserWithRole(2);
        $merchant = Merchant::factory()->approved()->create([
            'user_id' => $owner->id,
            'status' => 'approved',
        ]);

        $this->actingAs($other)
            ->apiDelete('/api/merchant/' . $merchant->slug)
            ->assertStatus(403);
    }

    // ======================
    // ASSET STREAMING
    // ======================

    #[Test]
    public function merchant_profile_picture_returns_404_when_logo_missing()
    {
        $merchant = Merchant::factory()->approved()->create([
            'logo_path' => null,
        ]);

        $this->get('/api/merchant-profile-pictures/' . $merchant->id)
            ->assertStatus(404);
    }

    #[Test]
    public function merchant_profile_picture_streams_file_when_exists()
    {
        Storage::disk('public')->put('logos/test.jpg', 'image-bytes');

        $merchant = Merchant::factory()->approved()->create([
            'logo_path' => 'logos/test.jpg',
        ]);

        $resp = $this->get('/api/merchant-profile-pictures/' . $merchant->id);
        $resp->assertStatus(200);
        $this->assertStringContainsString('max-age=31536000', (string) $resp->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', (string) $resp->headers->get('Cache-Control'));
    }

    #[Test]
    public function merchant_banner_returns_404_when_cover_missing()
    {
        $merchant = Merchant::factory()->approved()->create([
            'cover_path' => null,
        ]);

        $this->get('/api/merchant-banner/' . $merchant->id)
            ->assertStatus(404);
    }

    #[Test]
    public function merchant_banner_streams_file_when_exists()
    {
        Storage::disk('public')->put('covers/test.png', 'image-bytes');

        $merchant = Merchant::factory()->approved()->create([
            'cover_path' => 'covers/test.png',
        ]);

        $resp = $this->get('/api/merchant-banner/' . $merchant->id);
        $resp->assertStatus(200);
        $this->assertStringContainsString('max-age=31536000', (string) $resp->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', (string) $resp->headers->get('Cache-Control'));
    }
}
