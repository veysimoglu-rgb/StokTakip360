<?php

namespace App\Http\Controllers;

use App\Support\CompanyLogo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandingController extends Controller
{
    /** Streams the company logo to any signed-in user (the sidebar shows it on every page). */
    public function logo(): BinaryFileResponse
    {
        $path = CompanyLogo::path();

        abort_if($path === null, 404);

        $response = response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => CompanyLogo::mime($path),
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // The URL carries the (random) file name as ?v=, so a new upload is a new URL and this can be
        // cached hard. BinaryFileResponse defaults to "public"; the logo is for signed-in users only.
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');

        return $response;
    }
}
