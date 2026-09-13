<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mikrolens Management Center (MMC) — license verification (WP-11)
    |--------------------------------------------------------------------------
    |
    | MMC is the external, existing source of truth for licensing. These
    | values authenticate StokTakip360's read-only calls to MMC's
    | POST /wp-json/teknix360/v1/license/check endpoint. The secret must
    | never be exposed to Blade/JS/the browser — it is read here from
    | .env only and used server-side by MmcSigner.
    |
    */
    'mmc' => [
        'base_url' => env('MMC_BASE_URL'),
        'api_key' => env('MMC_API_KEY'),
        'secret' => env('MMC_SECRET'),
        'license_key' => env('MMC_LICENSE_KEY'),
        'timeout' => (int) env('MMC_TIMEOUT', 5),
    ],

];
