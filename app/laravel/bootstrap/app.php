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

// Include auto-authentication bootstrap - always loaded in Docker environment
require_once __DIR__ . '/autoauth.php';
