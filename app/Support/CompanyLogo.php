<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The optional company logo shown at the top of the sidebar.
 *
 * Stored on the private "local" disk (no `storage:link` needed) and streamed to signed-in users by
 * BrandingController, so it also works on hosts without symlinks. The setting holds only the
 * relative path; the file name is random, which doubles as the cache-busting version of the URL.
 * PNG / JPEG / WebP only — SVG is deliberately not accepted (it can carry scripts).
 */
class CompanyLogo
{
    public const KEY = 'company_logo';

    public const DIR = 'branding';

    /** extension => mime type, the only formats that are stored and served. */
    public const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    public const MAX_KB = 2048;

    public const MAX_SIDE_PX = 4000;

    /** Relative path of the stored logo, or null when there is none (or the file has gone missing). */
    public static function path(): ?string
    {
        $path = Setting::get(self::KEY);

        // Only names this class generates (branding/<random>.<png|jpg|webp>) are ever read back.
        if (! is_string($path) || ! preg_match('#^'.self::DIR.'/[A-Za-z0-9]+\.(?:'.implode('|', array_keys(self::TYPES)).')$#', $path)) {
            return null;
        }

        return Storage::disk('local')->exists($path) ? $path : null;
    }

    public static function url(): ?string
    {
        $path = self::path();

        return $path === null ? null : route('branding.logo', ['v' => pathinfo($path, PATHINFO_FILENAME)]);
    }

    public static function mime(string $path): string
    {
        return self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /** Stores the upload as the current logo and deletes the previous file. */
    public static function store(UploadedFile $file): string
    {
        $extension = match ($file->guessExtension()) {
            'jpeg', 'jpg', 'jpe' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            default => throw new \InvalidArgumentException('Unsupported logo type.'),
        };

        $previous = self::path();
        $path = $file->storeAs(self::DIR, Str::random(40).'.'.$extension, 'local');

        Setting::set(self::KEY, $path);

        if ($previous !== null && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }

        return $path;
    }

    public static function remove(): void
    {
        $path = self::path();

        Setting::set(self::KEY, null);

        if ($path !== null) {
            Storage::disk('local')->delete($path);
        }
    }
}
