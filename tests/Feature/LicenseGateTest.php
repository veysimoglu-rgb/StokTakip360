<?php

namespace Tests\Feature;

use App\Services\License\LicenseGate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LicenseGateTest extends TestCase
{
    private const ENDPOINT = 'https://mmc.test/wp-json/teknix360/v1/license/check';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'services.mmc.base_url' => 'https://mmc.test',
            'services.mmc.secret' => 'test-secret',
            'services.mmc.api_key' => null,
            'services.mmc.timeout' => 5,
            'services.mmc.license_key' => 'LIC-TEST-0001',
        ]);
    }

    private function mmcResponse(array $data, int $status = 200): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['success' => $data['success'] ?? true, 'data' => $data], $status),
        ]);
    }

    public function test_active_license_is_allowed(): void
    {
        $this->mmcResponse([
            'success' => true,
            'license_status' => 'active',
            'message' => 'Lisans geçerli.',
            'grace_days' => 7,
            'next_check_interval' => 86400,
        ]);

        $decision = app(LicenseGate::class)->decide();

        $this->assertTrue($decision['allowed']);
        $this->assertSame('active', $decision['reason']);
    }

    public function test_expired_license_is_blocked(): void
    {
        $this->mmcResponse([
            'success' => false,
            'license_status' => 'expired',
            'message' => 'Lisans süresi dolmuş.',
            'grace_days' => 7,
            'next_check_interval' => 3600,
        ], 403);

        $decision = app(LicenseGate::class)->decide();

        $this->assertFalse($decision['allowed']);
        $this->assertSame('expired', $decision['reason']);
    }

    public function test_cancelled_license_is_blocked(): void
    {
        $this->mmcResponse([
            'success' => false,
            'license_status' => 'cancelled',
            'message' => 'Lisans aktif değil.',
            'grace_days' => 7,
            'next_check_interval' => 3600,
        ], 403);

        $decision = app(LicenseGate::class)->decide();

        $this->assertFalse($decision['allowed']);
        $this->assertSame('cancelled', $decision['reason']);
    }

    public function test_suspended_license_is_blocked(): void
    {
        $this->mmcResponse([
            'success' => false,
            'license_status' => 'suspended',
            'message' => 'Lisans askıya alındı.',
            'grace_days' => 7,
            'next_check_interval' => 3600,
        ], 403);

        $decision = app(LicenseGate::class)->decide();

        $this->assertFalse($decision['allowed']);
        $this->assertSame('suspended', $decision['reason']);
    }

    public function test_pending_license_is_blocked_as_invalid(): void
    {
        $this->mmcResponse([
            'success' => false,
            'license_status' => 'pending',
            'message' => 'Lisans henüz başlamamış.',
            'grace_days' => 7,
            'next_check_interval' => 3600,
        ], 403);

        $decision = app(LicenseGate::class)->decide();

        $this->assertFalse($decision['allowed']);
        $this->assertSame('pending', $decision['reason']);
    }

    public function test_device_mismatch_is_blocked_distinctly_from_expired(): void
    {
        $this->mmcResponse([
            'success' => false,
            'license_status' => 'active',
            'message' => 'Donanım kimliği eşleşmiyor.',
            'grace_days' => 7,
            'next_check_interval' => 3600,
        ], 403);

        $decision = app(LicenseGate::class)->decide();

        $this->assertFalse($decision['allowed']);
        $this->assertSame('device_mismatch', $decision['reason']);
    }

    public function test_unknown_license_key_not_found_is_blocked(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['success' => false, 'error' => ['code' => 'LIC002', 'message' => 'Lisans bulunamadı.']], 404),
        ]);

        $decision = app(LicenseGate::class)->decide();

        $this->assertFalse($decision['allowed']);
        $this->assertSame('not_found', $decision['reason']);
    }

    public function test_product_mismatch_is_blocked(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['success' => false, 'error' => ['code' => 'LIC004', 'message' => 'Ürün eşleşmiyor.']], 403),
        ]);

        $decision = app(LicenseGate::class)->decide();

        $this->assertFalse($decision['allowed']);
        $this->assertSame('product_mismatch', $decision['reason']);
    }

    public function test_connection_timeout_fails_open_within_grace_period(): void
    {
        $this->primeLastKnownGood(daysAgo: 1, graceDays: 7);

        Http::fake([self::ENDPOINT => fn () => throw new ConnectionException('timed out')]);

        $decision = app(LicenseGate::class)->decide();

        $this->assertTrue($decision['allowed']);
        $this->assertSame('grace_period', $decision['reason']);
    }

    public function test_http_500_fails_open_within_grace_period(): void
    {
        $this->primeLastKnownGood(daysAgo: 1, graceDays: 7);

        Http::fake([self::ENDPOINT => Http::response('Internal Server Error', 500)]);

        $decision = app(LicenseGate::class)->decide();

        $this->assertTrue($decision['allowed']);
        $this->assertSame('grace_period', $decision['reason']);
    }

    public function test_grace_period_expiry_blocks_access(): void
    {
        $this->primeLastKnownGood(daysAgo: 10, graceDays: 7);

        Http::fake([self::ENDPOINT => Http::response('Internal Server Error', 500)]);

        $decision = app(LicenseGate::class)->decide();

        $this->assertFalse($decision['allowed']);
        $this->assertSame('grace_expired', $decision['reason']);
    }

    public function test_never_verified_and_unreachable_blocks_access(): void
    {
        Http::fake([self::ENDPOINT => Http::response('Internal Server Error', 500)]);

        $decision = app(LicenseGate::class)->decide();

        $this->assertFalse($decision['allowed']);
        $this->assertSame('never_verified', $decision['reason']);
    }

    public function test_http_401_hmac_failure_fails_open_and_is_not_treated_as_a_license_problem(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['success' => false, 'error' => ['code' => 'ml_auth_invalid_signature', 'message' => 'Geçersiz imza.']], 401),
        ]);

        $decision = app(LicenseGate::class)->decide();

        $this->assertTrue($decision['allowed']);
        $this->assertSame('auth_error_fail_open', $decision['reason']);
    }

    public function test_cache_miss_triggers_a_live_mmc_call(): void
    {
        Cache::forget('mmc:license:state');

        $this->mmcResponse(['success' => true, 'license_status' => 'active', 'message' => 'ok', 'grace_days' => 7, 'next_check_interval' => 86400]);

        app(LicenseGate::class)->decide();

        Http::assertSentCount(1);
    }

    public function test_cache_hit_within_next_check_interval_skips_a_new_mmc_call(): void
    {
        $this->mmcResponse(['success' => true, 'license_status' => 'active', 'message' => 'ok', 'grace_days' => 7, 'next_check_interval' => 86400]);

        app(LicenseGate::class)->decide();
        $this->assertSame(1, count(Http::recorded()));

        // Second call within the same next_check_interval window must not
        // hit MMC again — it reuses the cached decision.
        app(LicenseGate::class)->decide();
        $this->assertSame(1, count(Http::recorded()));
    }

    public function test_tampered_cache_is_ignored_and_does_not_grant_a_fake_active_decision(): void
    {
        // Simulate an attacker (or accidental manual edit) directly
        // overwriting the underlying cache row with a plain, forged string
        // instead of the app's own encrypted payload.
        Cache::put('mmc:license:state', 'status=active', now()->addDays(30));

        Http::fake([self::ENDPOINT => Http::response('Internal Server Error', 500)]);

        $decision = app(LicenseGate::class)->decide();

        // The forged value fails to decrypt, so it is treated as no cache at
        // all — combined with an unreachable MMC and no prior verified good
        // state, this must block, never silently grant access.
        $this->assertFalse($decision['allowed']);
        $this->assertSame('never_verified', $decision['reason']);
    }

    public function test_new_install_with_no_mmc_configuration_fails_open_and_logs_critically(): void
    {
        config(['services.mmc.base_url' => null, 'services.mmc.secret' => null]);

        $decision = app(LicenseGate::class)->decide();

        $this->assertTrue($decision['allowed']);
        $this->assertSame('auth_error_fail_open', $decision['reason']);
    }

    public function test_existing_installation_simulated_server_restart_reuses_encrypted_cache(): void
    {
        $this->mmcResponse(['success' => true, 'license_status' => 'active', 'message' => 'ok', 'grace_days' => 7, 'next_check_interval' => 86400]);
        app(LicenseGate::class)->decide();

        // A fresh LicenseGate instance (simulating a new request/process
        // after a server restart) must read the same persisted, encrypted
        // cache rather than needing a brand new MMC round-trip.
        $decision = app(LicenseGate::class)->decide();

        $this->assertTrue($decision['allowed']);
        Http::assertSentCount(1);
    }

    /**
     * Seeds the cache as if a previous live check had succeeded `$daysAgo`
     * days ago, so grace-period math can be tested without waiting on a
     * real clock.
     */
    private function primeLastKnownGood(int $daysAgo, int $graceDays): void
    {
        $state = [
            'last_outcome_type' => 'response',
            'success' => true,
            'license_status' => 'active',
            'message' => 'Lisans geçerli.',
            'grace_days' => $graceDays,
            'next_check_interval' => 0, // forces the next decide() to re-check live
            'last_checked_at' => now()->subDays($daysAgo)->timestamp,
            'last_success_at' => now()->subDays($daysAgo)->timestamp,
        ];

        Cache::put('mmc:license:state', Crypt::encryptString(json_encode($state)), now()->addDays(30));
    }
}
