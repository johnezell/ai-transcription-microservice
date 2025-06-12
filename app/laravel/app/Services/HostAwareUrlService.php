<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HostAwareUrlService
{
    protected $request;
    protected $fallbackUrls = [
        'ngrok' => 'https://transcriptions.ngrok.dev',
        'localhost' => 'http://localhost:8080'
    ];

    public function __construct(Request $request = null)
    {
        $this->request = $request ?: request();
    }

    /**
     * Detect if we're running on ngrok or localhost
     */
    public function detectHost(): string
    {
        $host = $this->request->getHost();
        
        // Check for ngrok domain
        if (str_contains($host, 'ngrok.dev') || str_contains($host, 'ngrok-free.app') || str_contains($host, 'ngrok.io')) {
            return 'ngrok';
        }
        
        // Check for localhost variations
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0']) || str_contains($host, 'localhost')) {
            return 'localhost';
        }
        
        // Default to localhost for unknown hosts
        return 'localhost';
    }

    /**
     * Detect if current request is using HTTPS
     */
    public function isSecure(): bool
    {
        return $this->request->isSecure() || 
               $this->request->header('X-Forwarded-Proto') === 'https' ||
               $this->request->header('X-Forwarded-Ssl') === 'on';
    }

    /**
     * Get the base URL based on current host and protocol
     */
    public function getBaseUrl(): string
    {
        $hostType = $this->detectHost();
        $isSecure = $this->isSecure();
        
        // For ngrok, use the actual request host instead of hardcoded fallback
        if ($hostType === 'ngrok') {
            $scheme = $isSecure ? 'https' : 'http';
            $host = $this->request->getHost();
            return $scheme . '://' . $host;
        }
        
        // Try to get from APP_URL first and adjust protocol if needed
        $appUrl = config('app.url');
        if ($appUrl && $appUrl !== 'http://localhost') {
            // Ensure protocol matches current request
            if ($isSecure && str_starts_with($appUrl, 'http://')) {
                return str_replace('http://', 'https://', $appUrl);
            } elseif (!$isSecure && str_starts_with($appUrl, 'https://')) {
                return str_replace('https://', 'http://', $appUrl);
            }
            return $appUrl;
        }
        
        // Fallback to detected host with proper protocol
        $baseUrl = $this->fallbackUrls[$hostType];
        
        // Ensure protocol matches current request
        if ($isSecure && str_starts_with($baseUrl, 'http://')) {
            return str_replace('http://', 'https://', $baseUrl);
        } elseif (!$isSecure && str_starts_with($baseUrl, 'https://')) {
            return str_replace('https://', 'http://', $baseUrl);
        }
        
        return $baseUrl;
    }

    /**
     * Generate asset URL with host awareness
     */
    public function asset(string $path): string
    {
        $baseUrl = $this->getBaseUrl();
        
        // Ensure path starts with forward slash
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        
        return $baseUrl . $path;
    }

    /**
     * Generate storage URL with host awareness
     */
    public function storageUrl(string $path): string
    {
        // Remove 'storage/' prefix if present to avoid duplication
        $cleanPath = str_replace('storage/', '', $path);
        
        return $this->asset('storage/' . $cleanPath);
    }

    /**
     * Generate route URL with host awareness
     */
    public function route(string $name, array $parameters = []): string
    {
        $baseUrl = $this->getBaseUrl();
        $routeUrl = route($name, $parameters, false); // Generate relative URL
        
        return $baseUrl . $routeUrl;
    }

    /**
     * Generate API URL with host awareness
     */
    public function apiUrl(string $endpoint): string
    {
        $baseUrl = $this->getBaseUrl();
        
        // Ensure endpoint starts with forward slash
        if (!str_starts_with($endpoint, '/')) {
            $endpoint = '/' . $endpoint;
        }
        
        // Add /api prefix if not present
        if (!str_starts_with($endpoint, '/api/')) {
            $endpoint = '/api' . $endpoint;
        }
        
        return $baseUrl . $endpoint;
    }

    /**
     * Generate URL with host awareness (for general use)
     */
    public function url(string $path): string
    {
        $baseUrl = $this->getBaseUrl();
        
        // Ensure path starts with forward slash
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        
        return $baseUrl . $path;
    }

    /**
     * Check if current request is from ngrok
     */
    public function isNgrok(): bool
    {
        return $this->detectHost() === 'ngrok';
    }

    /**
     * Check if current request is from localhost
     */
    public function isLocalhost(): bool
    {
        return $this->detectHost() === 'localhost';
    }

    /**
     * Get host information for debugging
     */
    public function getHostInfo(): array
    {
        return [
            'detected_host_type' => $this->detectHost(),
            'current_host' => $this->request->getHost(),
            'current_url' => $this->request->url(),
            'is_secure' => $this->isSecure(),
            'protocol' => $this->isSecure() ? 'https' : 'http',
            'app_url' => config('app.url'),
            'base_url' => $this->getBaseUrl(),
            'is_ngrok' => $this->isNgrok(),
            'is_localhost' => $this->isLocalhost(),
            'headers' => [
                'x-forwarded-proto' => $this->request->header('X-Forwarded-Proto'),
                'x-forwarded-ssl' => $this->request->header('X-Forwarded-Ssl'),
                'host' => $this->request->header('Host'),
            ]
        ];
    }
} 