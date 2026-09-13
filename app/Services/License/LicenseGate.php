<?php

namespace App\Services\License;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Central license decision engine for WP-11. MMC remains the sole source of
 * truth; this class only interprets its responses and manages the read-only
 * cache used to avoid calling MMC on every request.
 *
 * Decision rules (see WP-11 spec):
 *  - MMC explicitly says expired/cancelled/suspended/pending/invalid/
 *    not-found/product-mismatch/device-mismatch -> BLOCK (fail-closed).
 *  - MMC is technically unreachable (timeout/DNS/5xx) and we are still
 *    within `grace_days` of the last confirmed-good check -> ALLOW
 *    (fail-open on cache).
 *  - Unreachable AND past the grace window (or never confirmed good) ->
 *    BLOCK (falls back to the license screen).
 *  - 401/HMAC/config errors are NOT license problems -> always ALLOW, with
 *    a critical log line, never treated the same as an explicit MMC verdict.
 *
 * The cache entry is stored via Laravel's own encrypter (APP_KEY), not as a
 * plain "status=active" string, so editing the underlying cache row by hand
 * cannot forge an "active" decision — it fails to decrypt and is discarded.
 */
class LicenseGate
{
    private const CACHE_KEY = 'mmc:license:state';

    private const DEFAULT_RETRY_INTERVAL = 300; // seconds, used when MMC gave us no interval to trust

    public function __construct(
        private readonly MmcClient $client,
        private readonly InstallIdentifier $installIdentifier,
    ) {}

    /**
     * @return array{allowed: bool, reason: string, message: string}
     */
    public function decide(): array
    {
        $state = $this->readCache();
        $now = time();

        $needsLiveCheck = $state === null
            || $now >= ($state['last_checked_at'] + $state['next_check_interval']);

        if ($needsLiveCheck) {
            $state = $this->performLiveCheck($state);
        }

        return $this->evaluate($state, $now);
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    private function performLiveCheck(?array $previous): array
    {
        $result = $this->client->check($this->buildPayload());
        $now = time();

        $lastSuccessAt = $previous['last_success_at'] ?? null;
        $graceDays = $previous['grace_days'] ?? 7;

        $state = match ($result['type']) {
            'response' => [
                'last_outcome_type' => 'response',
                'success' => $result['success'],
                'license_status' => $result['license_status'],
                'message' => $result['message'],
                'grace_days' => $result['grace_days'],
                'next_check_interval' => $result['next_check_interval'],
                'last_checked_at' => $now,
                'last_success_at' => $result['success'] ? $now : $lastSuccessAt,
            ],
            'not_found', 'product_mismatch' => [
                'last_outcome_type' => $result['type'],
                'success' => false,
                'license_status' => $result['type'],
                'message' => $result['message'],
                'grace_days' => $graceDays,
                'next_check_interval' => self::DEFAULT_RETRY_INTERVAL,
                'last_checked_at' => $now,
                'last_success_at' => $lastSuccessAt,
            ],
            default => [ // auth_error | unreachable | not_configured
                'last_outcome_type' => $result['type'],
                'success' => null,
                'license_status' => null,
                'message' => $result['message'] ?? null,
                'grace_days' => $graceDays,
                'next_check_interval' => self::DEFAULT_RETRY_INTERVAL,
                'last_checked_at' => $now,
                'last_success_at' => $lastSuccessAt,
            ],
        };

        $this->storeCache($state);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{allowed: bool, reason: string, message: string}
     */
    private function evaluate(array $state, int $now): array
    {
        return match ($state['last_outcome_type']) {
            // When MMC says success=false but license_status is still
            // 'active', the only way that combination occurs (per MMC's own
            // handle_license_check logic) is a hardware/device mismatch —
            // every other rejection reason changes license_status itself
            // (expired/suspended/cancelled/pending).
            'response' => $state['success']
                ? ['allowed' => true, 'reason' => 'active', 'message' => $state['message']]
                : ['allowed' => false, 'reason' => $state['license_status'] === 'active' ? 'device_mismatch' : $state['license_status'], 'message' => $state['message']],

            'not_found', 'product_mismatch' => [
                'allowed' => false,
                'reason' => $state['last_outcome_type'],
                'message' => $state['message'],
            ],

            // Both are integration/configuration problems on our side, never
            // an MMC license verdict, so both must fail open (rule D).
            'auth_error', 'not_configured' => [
                'allowed' => true,
                'reason' => 'auth_error_fail_open',
                'message' => 'MMC ile kimlik doğrulama/yapılandırma sorunu — lisans engellenmedi.',
            ],

            default => $this->evaluateUnreachable($state, $now), // unreachable
        };
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{allowed: bool, reason: string, message: string}
     */
    private function evaluateUnreachable(array $state, int $now): array
    {
        $lastSuccessAt = $state['last_success_at'];

        if ($lastSuccessAt === null) {
            return [
                'allowed' => false,
                'reason' => 'never_verified',
                'message' => 'Lisans hiç doğrulanamadı ve MMC şu anda erişilemez durumda.',
            ];
        }

        $graceDeadline = $lastSuccessAt + ($state['grace_days'] * 86400);

        if ($now <= $graceDeadline) {
            return [
                'allowed' => true,
                'reason' => 'grace_period',
                'message' => 'MMC şu anda erişilemez durumda; son bilinen geçerli lisans onayı kullanılıyor.',
            ];
        }

        return [
            'allowed' => false,
            'reason' => 'grace_expired',
            'message' => 'MMC uzun süredir erişilemez durumda ve grace süresi doldu.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        return [
            'license_key' => config('services.mmc.license_key'),
            'product_id' => '',
            'product_name' => 'StokTakip360',
            'computer_name' => gethostname() ?: 'unknown',
            'hardware_id' => $this->installIdentifier->resolve(),
            'program_version' => '1.0.0',
            'windows_version' => php_uname(),
            'device_info' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(): ?array
    {
        $raw = Cache::get(self::CACHE_KEY);

        if (! $raw) {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($raw), true);

            return is_array($decoded) ? $decoded : null;
        } catch (DecryptException $e) {
            Log::critical('MMC license cache failed integrity check (possibly tampered) — ignoring and treating as absent.');

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function storeCache(array $state): void
    {
        Cache::put(self::CACHE_KEY, Crypt::encryptString(json_encode($state)), now()->addDays(30));
    }
}
