<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AutoAuthenticateTestUser
{
    /**
     * Handle an incoming request.
     */
    public function __invoke(Request $request, Closure $next): Response
    {
        // Only auto-authenticate if not already logged in
        if (!Auth::check()) {
            try {
                // Find the test user
                $testUser = User::where('email', 'johne@truefirestudios.com')->first();
                
                // Log in as the test user if found
                if ($testUser) {
                    Auth::login($testUser);
                }
            } catch (\Exception $e) {
                // Silently fail - don't break the application
                // This allows the middleware to not crash if DB is not set up yet
            }
        }

        return $next($request);
    }
} 