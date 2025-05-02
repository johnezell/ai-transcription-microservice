<?php

namespace App\Providers;

use App\Http\Middleware\AutoAuthenticateTestUser;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;

class AutoAuthServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(Kernel $kernel): void
    {
        // For Laravel 12, manually add the middleware
        // We don't need this since we're adding it in bootstrap/app.php
        // This provider is kept for reference
    }
} 