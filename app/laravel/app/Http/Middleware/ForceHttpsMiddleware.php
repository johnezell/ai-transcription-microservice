<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

class ForceHttpsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Force HTTPS URL generation when FORCE_HTTPS is enabled or when behind ngrok
        $shouldForceHttps = config('app.force_https') || 
                           env('FORCE_HTTPS', false) || 
                           $this->isNgrokRequest($request);

        if ($shouldForceHttps) {
            // Force HTTPS scheme for all URL generation
            URL::forceScheme('https');
            
            // Get the current host and build HTTPS URL
            $host = $request->getHost();
            $httpsUrl = 'https://' . $host;
            
            // Force the root URL to be HTTPS for all route generation
            URL::forceRootUrl($httpsUrl);
            
            // Also update the app.url config to HTTPS for consistency
            Config::set('app.url', $httpsUrl);
            
            // Force the request itself to be detected as HTTPS
            $request->server->set('HTTPS', 'on');
            $request->server->set('REQUEST_SCHEME', 'https');
            $request->server->set('HTTP_X_FORWARDED_PROTO', 'https');
            $request->server->set('HTTP_X_FORWARDED_PORT', '443');
            
            // Override the isSecure method result
            $request->headers->set('X-Forwarded-Proto', 'https');
        }

        // Trust proxies when specified or when ngrok request
        if (env('TRUSTED_PROXIES') || $this->isNgrokRequest($request)) {
            $request->setTrustedProxies(
                ['*'], 
                Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_AWS_ELB
            );
        }

        return $next($request);
    }

    /**
     * Check if the request is coming from ngrok
     */
    private function isNgrokRequest(Request $request): bool
    {
        $host = $request->getHost();
        return str_contains($host, 'ngrok.dev') || 
               str_contains($host, 'ngrok-free.app') || 
               str_contains($host, 'ngrok.io');
    }
} 