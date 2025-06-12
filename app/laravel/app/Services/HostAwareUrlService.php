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
     * Get the base URL based on current host
     */
    public function getBaseUrl(): string
    {
        $hostType = $this->detectHost();
        
        // Try to get from APP_URL first
        $appUrl = config('app.url');
        if ($appUrl && $appUrl !== 'http://localhost') {
            return $appUrl;
        }
        
        // Fallback to detected host
        return $this->fallbackUrls[$hostType];
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
            'app_url' => config('app.url'),
            'base_url' => $this->getBaseUrl(),
            'is_ngrok' => $this->isNgrok(),
            'is_localhost' => $this->isLocalhost(),
        ];
    }
} 