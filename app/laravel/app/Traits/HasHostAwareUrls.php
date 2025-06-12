<?php

namespace App\Traits;

use App\Services\HostAwareUrlService;

trait HasHostAwareUrls
{
    /**
     * Get the host-aware URL service instance
     */
    protected function getUrlService(): HostAwareUrlService
    {
        return app(HostAwareUrlService::class);
    }

    /**
     * Generate a host-aware asset URL
     */
    protected function hostAwareAsset(string $path): string
    {
        return $this->getUrlService()->asset($path);
    }

    /**
     * Generate a host-aware storage URL
     */
    protected function hostAwareStorageUrl(string $path): string
    {
        return $this->getUrlService()->storageUrl($path);
    }

    /**
     * Generate a host-aware route URL
     */
    protected function hostAwareRoute(string $name, array $parameters = []): string
    {
        return $this->getUrlService()->route($name, $parameters);
    }

    /**
     * Generate a host-aware API URL
     */
    protected function hostAwareApiUrl(string $endpoint): string
    {
        return $this->getUrlService()->apiUrl($endpoint);
    }

    /**
     * Generate a host-aware general URL
     */
    protected function hostAwareUrl(string $path): string
    {
        return $this->getUrlService()->url($path);
    }

    /**
     * Check if current request is from ngrok
     */
    protected function isNgrokRequest(): bool
    {
        return $this->getUrlService()->isNgrok();
    }

    /**
     * Check if current request is from localhost
     */
    protected function isLocalhostRequest(): bool
    {
        return $this->getUrlService()->isLocalhost();
    }

    /**
     * Check if current request is secure (HTTPS)
     */
    protected function isSecureRequest(): bool
    {
        return $this->getUrlService()->isSecure();
    }

    /**
     * Get storage URL with fallback for both storage paths and asset paths
     */
    protected function getStorageUrlWithFallback(?string $storagePath): ?string
    {
        if (!$storagePath) {
            return null;
        }

        // Handle different storage path formats
        if (str_starts_with($storagePath, 'storage/')) {
            // Already has storage/ prefix
            return $this->hostAwareAsset($storagePath);
        } elseif (str_starts_with($storagePath, '/')) {
            // Absolute path, convert to storage relative
            $relativePath = str_replace(storage_path('app/public/'), '', $storagePath);
            return $this->hostAwareStorageUrl($relativePath);
        } else {
            // Relative path within storage
            return $this->hostAwareStorageUrl($storagePath);
        }
    }
} 