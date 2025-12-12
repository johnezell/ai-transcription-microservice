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

        // Force the root URL based on APP_URL config
        // This is necessary because:
        // - In Docker, nginx listens on port 80 but is mapped to 8080 externally
        // - Behind ALB, we need HTTPS
        $appUrl = config('app.url');
        
        if ($appUrl) {
            URL::forceRootUrl($appUrl);
            
            // Force HTTPS for non-local environments
            if ($this->app->environment('staging', 'production')) {
                URL::forceScheme('https');
            }
        }
    }
}
