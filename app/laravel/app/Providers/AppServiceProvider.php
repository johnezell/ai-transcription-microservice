<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Force HTTPS for all URL generation in non-local environments
        // This ensures Inertia/Ziggy routes use HTTPS when behind ALB
        if ($this->app->environment('staging', 'production')) {
            URL::forceScheme('https');
            // Also force the root URL to ensure Ziggy uses HTTPS
            URL::forceRootUrl(config('app.url'));
        }
    }
}
