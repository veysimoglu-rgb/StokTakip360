<?php

namespace App\Http\Middleware;

use App\Services\License\LicenseGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks access only when LicenseGate has determined, per the WP-11
 * fail-open/fail-closed rules, that the license is genuinely invalid or the
 * grace period has expired. Never blocks on auth/HMAC/connectivity issues —
 * those fail open by design inside LicenseGate itself.
 */
class EnsureLicenseIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $decision = app(LicenseGate::class)->decide();

        if (! $decision['allowed']) {
            return response()->view('license.blocked', ['decision' => $decision], 403);
        }

        return $next($request);
    }
}
