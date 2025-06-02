<?php

namespace App\Providers;

use App\Services\CloudFrontSigningService;
use Illuminate\Support\ServiceProvider;

class CloudFrontServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(CloudFrontSigningService::class, function ($app) {
            return new CloudFrontSigningService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}