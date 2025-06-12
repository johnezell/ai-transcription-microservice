<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
