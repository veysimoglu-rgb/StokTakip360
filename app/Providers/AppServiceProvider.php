<?php

namespace App\Providers;

use App\Services\License\MmcClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // MmcClient's constructor takes scalar config values (WP-11), so it
        // needs an explicit binding for the container to resolve it.
        $this->app->bind(MmcClient::class, fn () => new MmcClient(
            config('services.mmc.base_url'),
            config('services.mmc.secret'),
            config('services.mmc.api_key'),
            (int) config('services.mmc.timeout', 5),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
