<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EnsureLicenseIsValidTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://mmc.test/wp-json/teknix360/v1/license/check';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'services.mmc.base_url' => 'https://mmc.test',
            'services.mmc.secret' => 'test-secret',
            'services.mmc.license_key' => 'LIC-TEST-0001',
        ]);
    }

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $user = User::factory()->create();
        $user->assignRole('Admin');

        return $user;
    }

    private function fakeActiveLicense(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['success' => true, 'data' => [
                'license_status' => 'active', 'message' => 'ok', 'grace_days' => 7, 'next_check_interval' => 86400,
            ]], 200),
        ]);
    }

    private function fakeExpiredLicense(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['success' => false, 'data' => [
                'license_status' => 'expired', 'message' => 'Lisans süresi dolmuş.', 'grace_days' => 7, 'next_check_interval' => 3600,
            ]], 403),
        ]);
    }

    public function test_authenticated_user_can_reach_dashboard_when_license_is_active(): void
    {
        $this->fakeActiveLicense();

        $response = $this->actingAs($this->admin())->get('/dashboard');

        $response->assertOk();
    }

    public function test_authenticated_user_is_blocked_when_license_is_explicitly_expired(): void
    {
        $this->fakeExpiredLicense();

        $response = $this->actingAs($this->admin())->get('/dashboard');

        $response->assertStatus(403);
        $response->assertViewIs('license.blocked');
    }

    public function test_login_page_is_not_gated_by_license_middleware(): void
    {
        $this->fakeExpiredLicense();

        $response = $this->get('/login');

        $response->assertOk();
    }

    public function test_forgot_password_page_is_not_gated_by_license_middleware(): void
    {
        $this->fakeExpiredLicense();

        $response = $this->get('/forgot-password');

        $response->assertOk();
    }

    public function test_logout_is_not_gated_by_license_middleware(): void
    {
        $this->fakeExpiredLicense();

        $response = $this->actingAs($this->admin())->post('/logout');

        $response->assertRedirect('/');
    }

    public function test_health_check_route_is_not_gated_by_license_middleware(): void
    {
        $this->fakeExpiredLicense();

        $response = $this->get('/up');

        $response->assertOk();
    }

    public function test_unreachable_mmc_with_no_prior_verification_blocks_a_brand_new_installation(): void
    {
        Http::fake([self::ENDPOINT => Http::response('Internal Server Error', 500)]);

        $response = $this->actingAs($this->admin())->get('/dashboard');

        $response->assertStatus(403);
    }

    public function test_hmac_auth_error_never_locks_out_the_customer(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['success' => false, 'error' => ['code' => 'ml_auth_invalid_signature', 'message' => 'Geçersiz imza.']], 401),
        ]);

        $response = $this->actingAs($this->admin())->get('/dashboard');

        $response->assertOk();
    }
}
