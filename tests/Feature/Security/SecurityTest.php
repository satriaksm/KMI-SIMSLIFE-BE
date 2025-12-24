<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test rate limiting on login endpoint
     */
    public function test_login_rate_limiting(): void
    {
        // Create a test user
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Try logging in 6 times (limit is 5)
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'test@example.com',
                'password' => 'wrong-password',
            ]);

            if ($i < 5) {
                // First 5 attempts should return 401 (unauthorized)
                $this->assertEquals(401, $response->status());
            } else {
                // 6th attempt should be rate limited
                $this->assertEquals(429, $response->status());
            }
        }
    }

    /**
     * Test that security headers are present
     */
    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/api/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
    }

    /**
     * Test mass assignment protection
     */
    public function test_mass_assignment_protection(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'nik' => '1234567890123456',
            'email_verified_at' => now(), // This should be ignored (guarded)
        ]);

        // User should be created but email_verified_at should be null
        $user = User::where('email', 'test@example.com')->first();
        $this->assertNull($user->email_verified_at);
    }

    /**
     * Test SQL injection prevention via hasRole helper
     */
    public function test_role_check_is_safe(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create(['name' => 'admin']);
        $user->roles()->attach($role);

        // This should work safely
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasRole('ADMIN')); // Case insensitive

        // SQL injection attempt (should return false, not error)
        $this->assertFalse($user->hasRole("admin' OR '1'='1"));
    }

    /**
     * Test that admin endpoints require admin role
     */
    public function test_admin_endpoints_require_admin_role(): void
    {
        // Create regular user
        $user = User::factory()->create();
        $customerRole = Role::factory()->create(['name' => 'customer']);
        $user->roles()->attach($customerRole);

        $token = $user->createToken('test-token')->plainTextToken;

        // Try accessing admin endpoint
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/admin/dashboard');

        // Should be forbidden
        $this->assertEquals(403, $response->status());
    }

    /**
     * Test input sanitization
     */
    public function test_input_sanitization(): void
    {
        $user = User::factory()->create();
        $adminRole = Role::factory()->create(['name' => 'admin']);
        $user->roles()->attach($adminRole);

        $token = $user->createToken('test-token')->plainTextToken;

        // Try to inject HTML/script
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/community/posts', [
            'content' => '<script>alert("XSS")</script>Hello',
            'visibility' => 'public',
        ]);

        // HTML should be stripped (depending on implementation)
        // This test assumes sanitization middleware is working
        $this->assertStringNotContainsString('<script>', $response->json('data.content') ?? '');
    }
}
