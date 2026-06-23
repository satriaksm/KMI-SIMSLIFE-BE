<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\City;
use App\Models\User;
use App\Models\Village;
use App\Models\District;
use App\Models\Province;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedRoles();
    }

    protected function createVerifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password123'),
        ]);
    }

    // ==========================
    // PROFILE SHOW
    // ==========================

    #[Test]
    public function user_can_view_profile()
    {
        $user = $this->createVerifiedUser();

        $response = $this->actingAs($user)
            ->apiGet('/api/profile');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'email' => $user->email,
                'name' => $user->name,
            ]);
    }

    #[Test]
    public function guest_cannot_view_profile()
    {
        $this->apiGet('/api/profile')
            ->assertStatus(401);
    }

    // ==========================
    // UPDATE PROFILE
    // ==========================

    #[Test]
    public function user_can_update_profile_basic_fields()
    {
        $user = $this->createVerifiedUser();

        $response = $this->actingAs($user)->apiPost('/api/profile/update', [
            'name' => 'New Name',
            'phone' => '08123456789',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'New Name',
                'phone' => '08123456789',
            ]);
    }

    #[Test]
    public function user_can_upload_profile_picture()
    {
        $user = $this->createVerifiedUser();

        $file = UploadedFile::fake()->image('avatar.jpg');

        $response = $this->actingAs($user)->apiPost('/api/profile/update', [
            'profile_picture' => $file,
        ]);

        $response->assertStatus(200);

        $user->refresh();

        $this->assertNotNull($user->profile_picture_path);
        Storage::disk('public')->assertExists($user->profile_picture_path);
    }

    #[Test]
    public function update_profile_validation_fails()
    {
        $user = $this->createVerifiedUser();

        $response = $this->actingAs($user)->apiPost('/api/profile/update', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
    }

    // ==========================
    // CHANGE PASSWORD
    // ==========================

    #[Test]
    public function user_can_change_password()
    {
        $user = $this->createVerifiedUser();

        $response = $this->actingAs($user)->apiPost('/api/profile/change-password', [
            'current_password' => 'password123',
            'new_password' => 'NewStrong123!',
            'new_password_confirmation' => 'NewStrong123!',
        ]);

        $response->assertStatus(200);

        $this->assertTrue(
            Hash::check('NewStrong123!', $user->fresh()->password)
        );
    }

    #[Test]
    public function password_change_fails_if_wrong_current_password()
    {
        $user = $this->createVerifiedUser();

        $response = $this->actingAs($user)->apiPost('/api/profile/change-password', [
            'current_password' => 'wrong-password',
            'new_password' => 'NewStrong123!',
            'new_password_confirmation' => 'NewStrong123!',
        ]);

        $response->assertStatus(422);
    }

    // ==========================
    // ADDRESS SHOW
    // ==========================

    #[Test]
    public function user_can_view_address()
    {
        $user = $this->createVerifiedUser();

        $response = $this->actingAs($user)->apiGet('/api/profile/address');

        $response->assertStatus(200);
    }

    // ==========================
    // ADDRESS UPSERT
    // ==========================

    #[Test]
    public function user_can_upsert_address()
    {
        $user = $this->createVerifiedUser();

        // Seed dummy region data
        $province = Province::factory()->create();
        $city = City::factory()->create(['province_id' => $province->id]);
        $district = District::factory()->create(['city_id' => $city->id]);
        $village = Village::factory()->create(['district_id' => $district->id]);

        $response = $this->actingAs($user)->apiPost('/api/profile/address', [
            'province_id' => $province->id,
            'city_id' => $city->id,
            'district_id' => $district->id,
            'village_id' => $village->id,
            'detail' => 'Jl. Testing No. 123',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'detail' => 'Jl. Testing No. 123',
            ]);
    }

    #[Test]
    public function address_validation_fails()
    {
        $user = $this->createVerifiedUser();

        $response = $this->actingAs($user)->apiPost('/api/profile/address', []);

        $response->assertStatus(422);
    }

    // ==========================
    // PROFILE PICTURE STREAM
    // ==========================

    #[Test]
    public function can_stream_profile_picture()
    {
        $user = $this->createVerifiedUser();

        $file = UploadedFile::fake()->image('avatar.jpg');
        $path = $file->store('profile_pictures', 'public');

        $user->update(['profile_picture_path' => $path]);

        $response = $this->get(route('profile-pictures.show', $user));

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    // ==========================
    // DELETE ACCOUNT
    // ==========================

    #[Test]
    public function user_can_delete_own_account()
    {
        $user = $this->createVerifiedUser();

        $response = $this->withCookies($this->getCsrfToken())
            ->actingAs($user)
            ->postJson('/api/profile', [
                'password' => 'password123',
                '_method' => 'DELETE',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'message' => 'Akun berhasil dihapus.'
            ]);

        // Ensure user really gone (pakai Eloquent agar konsisten dengan transaksi test Laravel)
        $this->assertNull(User::find($user->id));
    }


    #[Test]
    public function delete_account_fails_if_wrong_password()
    {
        $user = $this->createVerifiedUser();

        $response = $this->actingAs($user)->apiDelete('/api/profile', [
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
    }
}
