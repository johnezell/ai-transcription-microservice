<?php

namespace App\Services;

use Aws\CloudFront\CloudFrontClient;
use Exception;
use Illuminate\Support\Facades\Log;

class CloudFrontSigningService
{
    private CloudFrontClient $cloudFrontClient;
    private string $privateKeyPath;
    private string $keyPairId;
    private int $defaultExpirationSeconds;

    public function __construct()
    {
        $this->privateKeyPath = config('services.cloudfront.private_key_path', storage_path('app/cloudfront/pk-APKAJKYJ7CQO2ZKTVR4Q.pem'));
        $this->keyPairId = config('services.cloudfront.key_pair_id', 'APKAJKYJ7CQO2ZKTVR4Q');
        $this->defaultExpirationSeconds = config('services.cloudfront.default_expiration', 300);

        $this->cloudFrontClient = new CloudFrontClient([
            'region' => config('services.cloudfront.region', 'us-east-1'),
            'version' => 'latest',
        ]);
    }

    /**
     * Sign a CloudFront URL with custom policy
     *
     * @param string $server The CloudFront domain
     * @param string $file The file path
     * @param int $seconds Expiration time in seconds (default: 300)
     * @param bool $whitelist Whether to include IP whitelist (default: false)
     * @return string The signed URL
     * @throws Exception
     */
    public function signUrl(string $server, string $file = '', int $seconds = null, bool $whitelist = false): string
    {
        $seconds = $seconds ?? $this->defaultExpirationSeconds;
        $filePath = $server . $file;
        $expires = time() + $seconds;

        try {
            // Determine if we need IP restriction based on file extension and whitelist flag
            $ext = pathinfo($filePath, PATHINFO_EXTENSION);
            $restrictedExtensions = ['pdf', 'mp3', 'ptb', 'gp5'];
            $includeIpRestriction = $whitelist && !in_array(strtolower($ext), $restrictedExtensions);

            if ($includeIpRestriction && isset($_SERVER['REMOTE_ADDR'])) {
                // Create custom policy with IP restriction
                $policy = [
                    'Statement' => [
                        [
                            'Resource' => $filePath,
                            'Condition' => [
                                'IpAddress' => [
                                    'AWS:SourceIp' => $_SERVER['REMOTE_ADDR']
                                ],
                                'DateLessThan' => [
                                    'AWS:EpochTime' => $expires
                                ]
                            ]
                        ]
                    ]
                ];

                return $this->cloudFrontClient->getSignedUrl([
                    'url' => $filePath,
                    'policy' => json_encode($policy),
                    'private_key' => $this->getPrivateKey(),
                    'key_pair_id' => $this->keyPairId
                ]);
            } else {
                // Simple time-based expiration only
                return $this->cloudFrontClient->getSignedUrl([
                    'url' => $filePath,
                    'expires' => $expires,
                    'private_key' => $this->getPrivateKey(),
                    'key_pair_id' => $this->keyPairId
                ]);
            }
        } catch (Exception $e) {
            Log::error('CloudFront URL signing failed', [
                'error' => $e->getMessage(),
                'file_path' => $filePath,
                'expires' => $expires
            ]);
            throw new Exception('Failed to sign CloudFront URL: ' . $e->getMessage());
        }
    }

    /**
     * Sign multiple URLs at once
     *
     * @param array $urls Array of URLs to sign
     * @param int $seconds Expiration time in seconds
     * @param bool $whitelist Whether to include IP whitelist
     * @return array Array of signed URLs
     */
    public function signMultipleUrls(array $urls, int $seconds = null, bool $whitelist = false): array
    {
        $signedUrls = [];
        
        foreach ($urls as $key => $url) {
            try {
                if (is_array($url) && isset($url['server']) && isset($url['file'])) {
                    $signedUrls[$key] = $this->signUrl($url['server'], $url['file'], $seconds, $whitelist);
                } else {
                    // Assume it's a full URL
                    $signedUrls[$key] = $this->signUrl($url, '', $seconds, $whitelist);
                }
            } catch (Exception $e) {
                Log::warning('Failed to sign URL in batch', [
                    'key' => $key,
                    'url' => $url,
                    'error' => $e->getMessage()
                ]);
                $signedUrls[$key] = null;
            }
        }

        return $signedUrls;
    }

    /**
     * Get the private key content
     *
     * @return string
     * @throws Exception
     */
    private function getPrivateKey(): string
    {
        if (!file_exists($this->privateKeyPath)) {
            throw new Exception("CloudFront private key file not found at: {$this->privateKeyPath}");
        }

        $privateKey = file_get_contents($this->privateKeyPath);
        
        if ($privateKey === false) {
            throw new Exception("Failed to read CloudFront private key file: {$this->privateKeyPath}");
        }

        return $privateKey;
    }

    /**
     * Validate CloudFront configuration
     *
     * @return bool
     */
    public function validateConfiguration(): bool
    {
        try {
            $this->getPrivateKey();
            return !empty($this->keyPairId);
        } catch (Exception $e) {
            Log::error('CloudFront configuration validation failed', [
                'error' => $e->getMessage(),
                'private_key_path' => $this->privateKeyPath,
                'key_pair_id' => $this->keyPairId
            ]);
            return false;
        }
    }
}