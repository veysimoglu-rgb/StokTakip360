<?php

namespace App\Services\License;

/**
 * Produces the exact HMAC-SHA256 signature MMC's ML_Rest_Auth::verify_request()
 * expects: hash_hmac('sha256', "{timestamp}.{raw_body}", secret). The raw
 * body passed here must be byte-identical to what is actually sent over the
 * wire (MmcClient signs the same JSON string it posts), since MMC recomputes
 * the hash over its own received request body.
 */
class MmcSigner
{
    public function __construct(private readonly string $secret) {}

    public function sign(string $rawBody, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);
    }

    /**
     * @return array<string, string>
     */
    public function headers(string $rawBody, int $timestamp, ?string $apiKey = null): array
    {
        $headers = [
            'X-ML-Timestamp' => (string) $timestamp,
            'X-ML-Signature' => $this->sign($rawBody, $timestamp),
        ];

        if ($apiKey) {
            $headers['X-ML-API-Key'] = $apiKey;
        }

        return $headers;
    }
}
