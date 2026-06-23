<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Events\Registered;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;


class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    // ========================= //
    // Registration Tests
    // ========================= //

    // =========================
    // Register Validation Tests
    // =========================

    #[Test]
    public function register_validation_errors()
    {
        $response = $this->apiPost('api/auth/register', [
            'name' => '',
            'email' => 'not-an-email',
            'nik' => '123',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_register_requires_all_required_fields()
    {
        $response = $this->apiPost('api/auth/register', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'nik', 'password']);
    }

    public function test_register_name_must_be_string_and_max_255()
    {
        $response = $this->apiPost('api/auth/register', [
            'name' => str_repeat('a', 256),
            'email' => 'a@a.com',
            'nik' => '1234567890123456',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_register_email_must_be_valid_and_max_255()
    {
        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test',
            'email' => 'not-an-email',
            'nik' => '1234567890123456',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test',
            'email' => str_repeat('a', 250) . '@a.com',
            'nik' => '1234567890123456',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_phone_nullable_and_max_13()
    {
        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test',
            'email' => 'a@a.com',
            'phone' => str_repeat('1', 14),
            'nik' => '1234567890123456',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test',
            'email' => 'a@a.com',
            'nik' => '1234567890123456',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);
        $response->assertStatus(201);
    }

    public function test_register_nik_must_be_16_characters()
    {
        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test',
            'email' => 'a@a.com',
            'nik' => '123',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['nik']);

        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test',
            'email' => 'a@a.com',
            'nik' => '1234567890123456',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);
        $response->assertStatus(201);
    }

    public function test_register_password_must_be_confirmed()
    {
        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test',
            'email' => 'a@a.com',
            'nik' => '1234567890123456',
            'password' => 'Password1!',
            'password_confirmation' => 'Different1!',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_password_min_length_and_regex()
    {
        // Less than 8 chars
        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test',
            'email' => 'a@a.com',
            'nik' => '1234567890123456',
            'password' => 'P1!',
            'password_confirmation' => 'P1!',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        // No uppercase
        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test',
            'email' => 'a@a.com',
            'nik' => '1234567890123456',
            'password' => 'password1!',
            'password_confirmation' => 'password1!',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        // No digit
        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test',
            'email' => 'a@a.com',
            'nik' => '1234567890123456',
            'password' => 'Password!',
            'password_confirmation' => 'Password!',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        // No symbol
        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test',
            'email' => 'a@a.com',
            'nik' => '1234567890123456',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    // =========================
    // Complete Registration Tests
    // =========================
    #[Test]
    public function user_can_register_successfully()
    {
        Event::fake();

        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '08123456789',
            'nik' => '1234567890123456',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'message' => 'Registrasi berhasil. Silakan verifikasi email Anda sebelum login.',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'nik' => '1234567890123456',
        ]);

        Event::assertDispatched(Registered::class);
    }

    #[Test]
    public function cannot_register_with_existing_verified_email()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'nik' => '1234567890123456',
            'email_verified_at' => now(),
        ]);

        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '08123456789',
            'nik' => '9999999999999999',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['email' => ['Email sudah terdaftar.']]);
    }

    #[Test]
    public function can_register_with_existing_unverified_email()
    {
        Event::fake();

        $user = User::factory()->create([
            'email' => 'test@example.com',
            'nik' => '1234567890123456',
            'email_verified_at' => null,
            'status' => 'inactive',
        ]);

        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '08123456789',
            'nik' => '1234567890123456',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'message' => 'Registrasi berhasil. Silakan verifikasi email Anda sebelum login.',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'status' => 'active',
        ]);

        Event::assertDispatched(Registered::class);
    }

    #[Test]
    public function cannot_register_with_existing_verified_nik()
    {
        $user = User::factory()->create([
            'email' => 'other@example.com',
            'nik' => '1234567890123456',
            'email_verified_at' => now(),
        ]);

        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '08123456789',
            'nik' => '1234567890123456',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['nik' => ['NIK sudah terdaftar.']]);
    }

    #[Test]
    public function can_register_with_existing_unverified_nik()
    {
        Event::fake();

        $user = User::factory()->create([
            'email' => 'other@example.com',
            'nik' => '1234567890123456',
            'email_verified_at' => null,
            'status' => 'inactive',
        ]);

        $response = $this->apiPost('api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '08123456789',
            'nik' => '1234567890123456',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'message' => 'Registrasi berhasil. Silakan verifikasi email Anda sebelum login.',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'status' => 'active',
        ]);

        Event::assertDispatched(Registered::class);
    }


    // =========================
    // Login Tests
    // =========================
    #[Test]
    public function user_can_login_successfully()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
            'status' => 'active',
        ]);

        $response = $this->postJson('/login', [
            'email' => 'test@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'message' => 'Login berhasil.',
            ])
            ->assertJsonStructure(['user']);
    }

    #[Test]
    public function cannot_login_with_wrong_credentials()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/login', [
            'email' => 'test@example.com',
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Kredensial tidak valid.']);
    }

    #[Test]
    public function cannot_login_with_unverified_email()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => null,
        ]);

        $response = $this->postJson('/login', [
            'email' => 'test@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'Email belum terverifikasi.',
                'need_verify' => true,
            ]);
    }

    #[Test]
    public function cannot_login_when_user_is_blocked()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
            'status' => 'suspended',
        ]);

        $response = $this->postJson('/login', [
            'email' => 'test@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'Akun Anda telah di-suspend. Hubungi admin untuk informasi lebih lanjut.',
                'status' => 'suspended',
            ]);
    }


    // =========================
    // Authenticated User Data Tests
    // =========================
    #[Test]
    public function can_get_authenticated_user_data()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'email_verified_at' => now(),
            'status' => 'active',
        ]);

        // Project does not ship a RoleFactory; create role explicitly.
        $roleId = 3;
        $user->roles()->syncWithoutDetaching([$roleId]);

        $this->actingAs($user);

        $response = $this->apiGet('api/me');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->assertJsonStructure(['roles', 'merchants']);
    }

    #[Test]
    public function cannot_get_user_data_when_not_authenticated()
    {
        $response = $this->apiGet('api/me');
        $response->assertStatus(401);
    }


    // =========================
    // Logout Tests
    // =========================
    #[Test]
    public function user_can_logout_successfully()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/logout');

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Logout berhasil.']);
    }

    #[Test]
    public function logout_without_authentication_should_return_unauthorized()
    {
        $response = $this->postJson('/logout');
        $response->assertStatus(401);
    }
}
