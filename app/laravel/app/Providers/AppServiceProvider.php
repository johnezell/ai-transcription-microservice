<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use App\Services\HostAwareUrlService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register custom priority queue connector
        $this->app->resolving('queue', function ($manager) {
            $manager->addConnector('priority-database', function () {
                return new \App\Queue\Connectors\PriorityDatabaseConnector($this->app['db']);
            });
        });

        // Register the HostAwareUrlService
        $this->app->singleton(HostAwareUrlService::class, function ($app) {
            return new HostAwareUrlService();
        });
        
        // Register alias for easier access
        $this->app->alias(HostAwareUrlService::class, 'host-aware-url');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
