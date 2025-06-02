<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
                // Find or create the test user
                $testUser = User::firstOrCreate(
                    ['email' => 'johne@truefirestudios.com'],
                    [
                        'name' => 'John Ezell',
                        'password' => Hash::make('password'),
                        'email_verified_at' => now(),
                    ]
                );
                
                // Log in as the test user
                Auth::login($testUser);
            } catch (\Exception $e) {
                // Silently fail - don't break the application
                // This allows the middleware to not crash if DB is not set up yet
            }
        }

        return $next($request);
    }
} 