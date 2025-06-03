<?php

/**
 * TrueFire CloudFront Signing Test Script
 * 
 * This script tests CloudFront signing with the actual TrueFire domain and URLs.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\CloudFrontSigningService;
use Illuminate\Support\Facades\Config;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== TrueFire CloudFront URL Signing Test ===\n\n";

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
    
    // Test 2: Test with actual TrueFire CloudFront domain
    echo "2. Testing TrueFire CloudFront Domain...\n";
    
    // This is the actual TrueFire CloudFront domain from the Segment model
    $truefireCloudFrontDomain = 'https://d3ldx91n93axbt.cloudfront.net';
    $testVideoPath = '/test_video_med.mp4'; // Example path
    
    echo "   Testing domain: {$truefireCloudFrontDomain}\n";
    echo "   Test video path: {$testVideoPath}\n";
    
    // Test with different expiration times
    $expirationTimes = [300, 3600, 86400, 2592000]; // 5 min, 1 hour, 1 day, 30 days
    
    foreach ($expirationTimes as $expiration) {
        echo "\n   Testing with {$expiration} second expiration...\n";
        
        try {
            $signedUrl = $cloudFrontService->signUrl($truefireCloudFrontDomain, $testVideoPath, $expiration, false);
            echo "   ✓ Signed URL generated successfully!\n";
            echo "   URL: " . substr($signedUrl, 0, 120) . "...\n";
            
            // Parse the URL to check parameters
            $parsedUrl = parse_url($signedUrl);
            parse_str($parsedUrl['query'], $queryParams);
            
            echo "   Parameters:\n";
            echo "     - Expires: " . ($queryParams['Expires'] ?? 'Not found') . "\n";
            echo "     - Key-Pair-Id: " . ($queryParams['Key-Pair-Id'] ?? 'Not found') . "\n";
            echo "     - Signature: " . (isset($queryParams['Signature']) ? 'Present ✓' : 'Missing ✗') . "\n";
            
            // Check if expiration timestamp is in the future
            $expiresTimestamp = $queryParams['Expires'] ?? 0;
            $currentTime = time();
            $timeUntilExpiry = $expiresTimestamp - $currentTime;
            
            echo "     - Current time: " . date('Y-m-d H:i:s', $currentTime) . " (timestamp: {$currentTime})\n";
            echo "     - Expires at: " . date('Y-m-d H:i:s', $expiresTimestamp) . " (timestamp: {$expiresTimestamp})\n";
            echo "     - Time until expiry: " . $timeUntilExpiry . " seconds\n";
            echo "     - Expiration valid: " . ($timeUntilExpiry > 0 ? 'Yes ✓' : 'No ✗') . "\n";
            
        } catch (\Exception $e) {
            echo "   ❌ Error: " . $e->getMessage() . "\n";
        }
    }
    
    // Test 3: Test with a sample video path similar to what TrueFire uses
    echo "\n3. Testing with TrueFire-style video path...\n";
    
    // TrueFire videos typically have paths like: some_video_name_med.mp4
    $sampleTruefireVideo = 'sample_lesson_intro_med.mp4';
    
    try {
        $signedUrl = $cloudFrontService->signUrl($truefireCloudFrontDomain, '/' . $sampleTruefireVideo, 3600, false);
        echo "   ✓ TrueFire-style URL signed successfully!\n";
        echo "   Full URL: {$truefireCloudFrontDomain}/{$sampleTruefireVideo}\n";
        echo "   Signed URL: " . substr($signedUrl, 0, 120) . "...\n";
        
    } catch (\Exception $e) {
        echo "   ❌ Error signing TrueFire-style URL: " . $e->getMessage() . "\n";
    }
    
    // Test 4: Test the exact method used by Segment model
    echo "\n4. Testing Segment Model Method...\n";
    
    // Simulate what the Segment model does
    $testVideo = 'mp4:sample_video'; // This is what might be in the database
    $processedVideo = str_replace('mp4:', '', $testVideo) . '_med.mp4'; // sample_video_med.mp4
    $fullUrl = $truefireCloudFrontDomain . '/' . $processedVideo;
    
    echo "   Original video field: {$testVideo}\n";
    echo "   Processed video: {$processedVideo}\n";
    echo "   Full URL to sign: {$fullUrl}\n";
    
    try {
        // This mimics exactly what Segment::getSignedUrl() does
        $signedUrl = $cloudFrontService->signUrl($fullUrl, '', 2592000); // 30 days default
        echo "   ✓ Segment-style URL signed successfully!\n";
        echo "   Signed URL: " . substr($signedUrl, 0, 120) . "...\n";
        
        // Test if we can make a HEAD request to check if the URL is accessible
        echo "\n   Testing URL accessibility...\n";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $signedUrl);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        echo "   HTTP Response Code: {$httpCode}\n";
        
        if ($httpCode == 200) {
            echo "   ✅ URL is accessible! (HTTP 200)\n";
        } elseif ($httpCode == 403) {
            echo "   ❌ Access Forbidden (HTTP 403) - This is the issue!\n";
            echo "   This indicates a CloudFront authentication/authorization problem.\n";
        } elseif ($httpCode == 404) {
            echo "   ⚠️  File not found (HTTP 404) - File doesn't exist, but signing works\n";
        } else {
            echo "   ⚠️  Unexpected response code: {$httpCode}\n";
            if ($error) {
                echo "   cURL Error: {$error}\n";
            }
        }
        
    } catch (\Exception $e) {
        echo "   ❌ Error: " . $e->getMessage() . "\n";
    }
    
    echo "\n=== Configuration Summary ===\n";
    echo "Private Key Path: " . config('services.cloudfront.private_key_path') . "\n";
    echo "Key Pair ID: " . config('services.cloudfront.key_pair_id') . "\n";
    echo "Default Expiration: " . config('services.cloudfront.default_expiration') . " seconds\n";
    echo "Region: " . config('services.cloudfront.region') . "\n";
    echo "TrueFire CloudFront Domain: {$truefireCloudFrontDomain}\n";
    
    echo "\n=== Diagnosis ===\n";
    echo "If you see HTTP 403 errors above, the issue is likely:\n";
    echo "1. CloudFront distribution is not configured with the correct trusted key groups\n";
    echo "2. The public key corresponding to the private key is not uploaded to AWS\n";
    echo "3. The CloudFront distribution doesn't have the key group associated\n";
    echo "4. The private key doesn't match the public key in AWS\n";
    echo "5. The CloudFront distribution settings are restricting access\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}