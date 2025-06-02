<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        api: __DIR__.'/../routes/api.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Always add the auto-authentication middleware
        // (docker containers are always considered local/development)
        $middleware->append(\App\Http\Middleware\AutoAuthenticateTestUser::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();

// Auto-authentication is handled by the AutoAuthenticateTestUser middleware
// No need to include autoauth.php here as it runs too early in the bootstrap process
