<?php

namespace App\Services\License;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Talks to MMC's existing, live POST /wp-json/teknix360/v1/license/check
 * endpoint — read-only verification only, never a write/management call.
 *
 * The result is normalized into one of a small set of outcome types so
 * LicenseGate never has to inspect raw HTTP status codes itself. The
 * distinction that matters most: `auth_error`/`unreachable` are NOT license
 * verdicts (MMC never actually evaluated the license), while `response`
 * (success may still be false inside it), `not_found` and `product_mismatch`
 * are MMC explicitly telling us something about the license itself.
 */
class MmcClient
{
    public function __construct(
        private readonly ?string $baseUrl,
        private readonly ?string $secret,
        private readonly ?string $apiKey,
        private readonly int $timeout,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function check(array $payload): array
    {
        if (! $this->baseUrl || ! $this->secret) {
            Log::critical('MMC license check skipped: mmc.base_url/mmc.secret not configured.');

            return ['type' => 'not_configured'];
        }

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $headers = (new MmcSigner($this->secret))->headers($rawBody, $timestamp, $this->apiKey);

        try {
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->retry(1, 250)
                ->withBody($rawBody, 'application/json')
                ->post(rtrim($this->baseUrl, '/').'/wp-json/teknix360/v1/license/check');
        } catch (ConnectionException|Throwable $e) {
            Log::warning('MMC license check unreachable: '.$e->getMessage());

            return ['type' => 'unreachable', 'message' => $e->getMessage()];
        }

        return $this->normalize($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(Response $response): array
    {
        $status = $response->status();
        $body = $response->json() ?? [];

        // 401: missing/expired/invalid HMAC signature (ML_Rest_Auth). This is
        // an integration/config problem, never a license verdict.
        if ($status === 401) {
            Log::critical('MMC license check auth failure (401): '.($body['error']['message'] ?? 'unknown'));

            return ['type' => 'auth_error', 'message' => $body['error']['message'] ?? 'HMAC/timestamp doğrulaması başarısız.'];
        }

        // 400 LIC001: we sent no license_key at all — our own config gap,
        // not MMC declaring a specific license invalid.
        if ($status === 400) {
            Log::critical('MMC license check config error (400): '.($body['error']['message'] ?? 'unknown'));

            return ['type' => 'auth_error', 'message' => $body['error']['message'] ?? 'İstek yapılandırma hatası.'];
        }

        // 404 LIC002: the configured license_key does not exist in MMC at all.
        if ($status === 404) {
            return ['type' => 'not_found', 'message' => $body['error']['message'] ?? 'Lisans bulunamadı.'];
        }

        // 429: rate limited — transient/operational, not a license verdict.
        if ($status === 429) {
            return ['type' => 'unreachable', 'message' => $body['error']['message'] ?? 'Rate limited.'];
        }

        // 503: MMC itself reports auth not configured server-side.
        if ($status === 503) {
            Log::critical('MMC reports API auth not configured server-side.');

            return ['type' => 'auth_error', 'message' => 'MMC API auth not configured.'];
        }

        // 200/403 with a structured license_status payload — the normal
        // license/check business response, whether success is true or false
        // (false covers expired/suspended/cancelled/pending/device mismatch).
        if (array_key_exists('success', $body) && array_key_exists('data', $body)) {
            // 403 LIC004 product mismatch has no `data` in some MMC versions;
            // guarded above by the array_key_exists('data', ...) check, so a
            // bare product-mismatch 403 falls through to the generic branch
            // below instead of being misread as a license verdict.
            return [
                'type' => 'response',
                'success' => (bool) $body['success'],
                'license_status' => $body['data']['license_status'] ?? 'unknown',
                'message' => $body['data']['message'] ?? '',
                'expire_date' => $body['data']['expire_date'] ?? null,
                'grace_days' => (int) ($body['data']['grace_days'] ?? 7),
                'next_check_interval' => (int) ($body['data']['next_check_interval'] ?? 3600),
                'server_time' => $body['data']['server_time'] ?? null,
            ];
        }

        // 403 LIC004 product mismatch (no `data` key): MMC explicitly knows
        // the license but says it doesn't apply to this product.
        if ($status === 403) {
            return ['type' => 'product_mismatch', 'message' => $body['error']['message'] ?? 'Ürün eşleşmiyor.'];
        }

        // Anything else (5xx, malformed body, etc.) is treated as MMC being
        // technically unreachable/unusable right now — never a license
        // verdict — so LicenseGate can fall back to the grace/cache path.
        Log::warning("MMC license check returned unexpected HTTP {$status}.");

        return ['type' => 'unreachable', 'message' => "Unexpected HTTP {$status}."];
    }
}
