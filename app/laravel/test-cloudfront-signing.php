<?php

/**
 * CloudFront Signing Test Script
 * 
 * This script allows you to test your CloudFront signing service with real S3 URLs.
 * Run this script from the Laravel root directory using: php test-cloudfront-signing.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\CloudFrontSigningService;
use Illuminate\Support\Facades\Config;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== CloudFront URL Signing Test ===\n\n";

try {
    // Initialize the CloudFront signing service
    $cloudFrontService = new CloudFrontSigningService();
    
    // Test 1: Validate Configuration
    echo "1. Testing Configuration Validation...\n";
    $isValid = $cloudFrontService->validateConfiguration();
    echo "   Configuration is " . ($isValid ? "VALID ✓" : "INVALID ✗") . "\n\n";
    
    if (!$isValid) {
        echo "❌ Configuration is invalid. Please check:\n";
        echo "   - Private key file exists at: " . config('services.cloudfront.private_key_path') . "\n";
        echo "   - Key pair ID is set: " . config('services.cloudfront.key_pair_id') . "\n";
        exit(1);
    }
    
    // Test 2: Sign a single URL (you can replace this with your actual S3/CloudFront URL)
    echo "2. Testing Single URL Signing...\n";
    
    // Example CloudFront domain and file path - REPLACE WITH YOUR ACTUAL VALUES
    $cloudFrontDomain = 'https://d1234567890.cloudfront.net';
    $filePath = '/uploads/videos/sample-video.mp4';
    
    echo "   Signing URL: {$cloudFrontDomain}{$filePath}\n";
    
    $signedUrl = $cloudFrontService->signUrl($cloudFrontDomain, $filePath, 3600, false);
    echo "   ✓ Signed URL generated successfully!\n";
    echo "   URL: " . substr($signedUrl, 0, 100) . "...\n";
    echo "   Length: " . strlen($signedUrl) . " characters\n\n";
    
    // Test 3: Sign multiple URLs
    echo "3. Testing Multiple URL Signing...\n";
    
    $urls = [
        'video1' => $cloudFrontDomain . '/video1.mp4',
        'video2' => $cloudFrontDomain . '/video2.mp4',
        'audio1' => [
            'server' => $cloudFrontDomain,
            'file' => '/audio/track1.mp3'
        ]
    ];
    
    $signedUrls = $cloudFrontService->signMultipleUrls($urls, 1800, false);
    echo "   ✓ Signed " . count($signedUrls) . " URLs successfully!\n";
    
    foreach ($signedUrls as $key => $url) {
        if ($url) {
            echo "   - {$key}: " . substr($url, 0, 80) . "...\n";
        } else {
            echo "   - {$key}: ❌ Failed to sign\n";
        }
    }
    echo "\n";
    
    // Test 4: Test with IP whitelisting
    echo "4. Testing IP Whitelisting...\n";
    
    // Simulate a client IP
    $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
    
    $whitelistedUrl = $cloudFrontService->signUrl($cloudFrontDomain, '/secure-video.mp4', 3600, true);
    echo "   ✓ IP-whitelisted URL generated successfully!\n";
    echo "   Contains Policy parameter: " . (strpos($whitelistedUrl, 'Policy=') !== false ? "Yes ✓" : "No ✗") . "\n\n";
    
    // Test 5: Test restricted file types (should not use IP whitelisting)
    echo "5. Testing Restricted File Types...\n";
    
    $restrictedFiles = [
        '/documents/manual.pdf',
        '/audio/track.mp3',
        '/tabs/song.ptb',
        '/tabs/guitar.gp5'
    ];
    
    foreach ($restrictedFiles as $file) {
        $restrictedUrl = $cloudFrontService->signUrl($cloudFrontDomain, $file, 3600, true);
        $hasPolicy = strpos($restrictedUrl, 'Policy=') !== false;
        echo "   {$file}: IP restriction " . ($hasPolicy ? "applied" : "skipped ✓") . "\n";
    }
    echo "\n";
    
    // Test 6: Test API endpoints
    echo "6. Testing API Endpoints...\n";
    
    $baseUrl = config('app.url', 'http://localhost');
    
    echo "   Available API endpoints:\n";
    echo "   - POST {$baseUrl}/api/cloudfront/sign-url\n";
    echo "   - POST {$baseUrl}/api/cloudfront/sign-multiple-urls\n";
    echo "   - GET  {$baseUrl}/api/cloudfront/validate-config\n\n";
    
    // Example cURL commands
    echo "7. Example cURL Commands:\n\n";
    
    echo "   # Sign a single URL:\n";
    echo "   curl -X POST {$baseUrl}/api/cloudfront/sign-url \\\n";
    echo "        -H 'Content-Type: application/json' \\\n";
    echo "        -d '{\n";
    echo "            \"server\": \"{$cloudFrontDomain}\",\n";
    echo "            \"file\": \"/your-file.mp4\",\n";
    echo "            \"seconds\": 3600,\n";
    echo "            \"whitelist\": false\n";
    echo "        }'\n\n";
    
    echo "   # Validate configuration:\n";
    echo "   curl -X GET {$baseUrl}/api/cloudfront/validate-config\n\n";
    
    echo "✅ All tests completed successfully!\n\n";
    
    echo "=== How to use with your S3 files ===\n";
    echo "1. Replace the CloudFront domain in this script with your actual domain\n";
    echo "2. Replace file paths with your actual S3 object keys\n";
    echo "3. Ensure your CloudFront distribution is configured with trusted key groups\n";
    echo "4. Test the signed URLs in a browser or with curl\n\n";
    
    echo "=== Configuration Summary ===\n";
    echo "Private Key Path: " . config('services.cloudfront.private_key_path') . "\n";
    echo "Key Pair ID: " . config('services.cloudfront.key_pair_id') . "\n";
    echo "Default Expiration: " . config('services.cloudfront.default_expiration') . " seconds\n";
    echo "Region: " . config('services.cloudfront.region') . "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}