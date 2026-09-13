<?php

namespace App\Services\License;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Resolves a durable identifier for this StokTakip360 installation, sent to
 * MMC as `hardware_id`. Stored as a plain file under storage/app (not a DB
 * table — storage/app persists across deploys the same way .env does),
 * generated once on first use and reused forever after.
 */
class InstallIdentifier
{
    private const PATH = 'mmc/install_id';

    public function resolve(): string
    {
        $disk = Storage::disk('local');

        if ($disk->exists(self::PATH)) {
            $id = trim($disk->get(self::PATH));

            if ($id !== '') {
                return $id;
            }
        }

        $id = (string) Str::uuid();
        $disk->put(self::PATH, $id);

        return $id;
    }
}
