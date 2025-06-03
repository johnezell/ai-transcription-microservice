<?php

/**
 * Simple CloudFront 403 Error Test
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\CloudFrontSigningService;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== CloudFront 403 Error Diagnosis ===\n\n";

try {
    $cloudFrontService = new CloudFrontSigningService();
    
    // Test with the actual TrueFire domain
    $truefireDomain = 'https://d3ldx91n93axbt.cloudfront.net';
    $testFile = '/test_video_med.mp4';
    
    echo "1. Generating signed URL...\n";
    $signedUrl = $cloudFrontService->signUrl($truefireDomain, $testFile, 3600, false);
    echo "   ✓ Signed URL: " . substr($signedUrl, 0, 100) . "...\n\n";
    
    // Parse URL components
    $parsedUrl = parse_url($signedUrl);
    parse_str($parsedUrl['query'], $params);
    
    echo "2. URL Components:\n";
    echo "   Domain: {$parsedUrl['host']}\n";
    echo "   Path: {$parsedUrl['path']}\n";
    echo "   Expires: {$params['Expires']} (" . date('Y-m-d H:i:s', $params['Expires']) . ")\n";
    echo "   Key-Pair-Id: {$params['Key-Pair-Id']}\n";
    echo "   Signature: " . substr($params['Signature'], 0, 20) . "...\n\n";
    
    echo "3. Testing URL accessibility...\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $signedUrl);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'TrueFire-Test/1.0');
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "   HTTP Response: {$httpCode}\n";
    
    if ($httpCode == 403) {
        echo "   ❌ CONFIRMED: 403 Forbidden Error\n";
        echo "\n=== ROOT CAUSE ANALYSIS ===\n";
        echo "The 403 error indicates CloudFront authentication failure.\n";
        echo "Most likely causes:\n";
        echo "1. ❌ CloudFront distribution not configured with trusted key groups\n";
        echo "2. ❌ Public key not uploaded to AWS or doesn't match private key\n";
        echo "3. ❌ Key group not associated with CloudFront distribution\n";
        echo "4. ❌ CloudFront distribution restricts access to signed URLs only\n";
        echo "5. ❌ Private key file doesn't match the public key in AWS\n";
    } elseif ($httpCode == 404) {
        echo "   ⚠️  404 Not Found - File doesn't exist but signing works\n";
    } elseif ($httpCode == 200) {
        echo "   ✅ 200 OK - URL works correctly!\n";
    } else {
        echo "   ⚠️  Unexpected response: {$httpCode}\n";
    }
    
    if ($error) {
        echo "   cURL Error: {$error}\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}