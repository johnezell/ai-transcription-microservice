<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\CloudFrontSigningService;
use Illuminate\Support\Facades\Log;

echo "=== CloudFront URL Generation Debug ===\n\n";

try {
    // Create the service
    $cloudFrontService = new CloudFrontSigningService();
    
    // Test the exact same parameters as V3 job
    $segmentId = "test-segment-1748982286";
    $cloudFrontUrl = "https://d2kum0w8xvhbpf.cloudfront.net/truefire/{$segmentId}.mp4";
    $expirationSeconds = 2 * 60 * 60; // 2 hours
    
    echo "1. Input Parameters:\n";
    echo "   Segment ID: {$segmentId}\n";
    echo "   CloudFront URL: {$cloudFrontUrl}\n";
    echo "   Expiration Seconds: {$expirationSeconds}\n";
    echo "   Current Time: " . date('Y-m-d H:i:s') . "\n";
    echo "   Expected Expiry: " . date('Y-m-d H:i:s', time() + $expirationSeconds) . "\n\n";
    
    echo "2. CloudFrontSigningService.signUrl() Call:\n";
    echo "   Method: signUrl(server, file, seconds, whitelist)\n";
    echo "   server = '{$cloudFrontUrl}'\n";
    echo "   file = ''\n";
    echo "   seconds = {$expirationSeconds}\n";
    echo "   whitelist = false\n\n";
    
    // Add debug logging to see what happens inside signUrl
    echo "3. Internal Processing (simulated):\n";
    $server = $cloudFrontUrl;
    $file = '';
    $seconds = $expirationSeconds;
    $filePath = $server . $file;
    $expires = time() + $seconds;
    
    echo "   \$filePath = \$server . \$file = '{$server}' . '{$file}' = '{$filePath}'\n";
    echo "   \$expires = time() + \$seconds = " . time() . " + {$seconds} = {$expires}\n";
    echo "   \$expires (formatted) = " . date('Y-m-d H:i:s', $expires) . "\n\n";
    
    // Now call the actual service
    echo "4. Calling CloudFrontSigningService.signUrl()...\n";
    $signedUrl = $cloudFrontService->signUrl($cloudFrontUrl, '', $expirationSeconds);
    
    echo "5. Result Analysis:\n";
    echo "   Signed URL Length: " . strlen($signedUrl) . "\n";
    echo "   Contains 'Expires': " . (strpos($signedUrl, 'Expires') !== false ? 'YES' : 'NO') . "\n";
    echo "   Contains 'Signature': " . (strpos($signedUrl, 'Signature') !== false ? 'YES' : 'NO') . "\n";
    echo "   Contains 'Key-Pair-Id': " . (strpos($signedUrl, 'Key-Pair-Id') !== false ? 'YES' : 'NO') . "\n\n";
    
    // Check for the malformation pattern
    $expectedFilename = "{$segmentId}.mp4";
    $malformedPattern = $expectedFilename . date('Y-m-d');
    
    echo "6. Malformation Check:\n";
    echo "   Expected filename: {$expectedFilename}\n";
    echo "   Malformed pattern: {$malformedPattern}\n";
    echo "   URL contains malformed pattern: " . (strpos($signedUrl, $malformedPattern) !== false ? 'YES - PROBLEM FOUND!' : 'NO') . "\n\n";
    
    // Extract and analyze URL components
    echo "7. URL Component Analysis:\n";
    $urlParts = parse_url($signedUrl);
    echo "   Scheme: " . ($urlParts['scheme'] ?? 'N/A') . "\n";
    echo "   Host: " . ($urlParts['host'] ?? 'N/A') . "\n";
    echo "   Path: " . ($urlParts['path'] ?? 'N/A') . "\n";
    echo "   Query: " . ($urlParts['query'] ?? 'N/A') . "\n\n";
    
    // Check if path contains timestamp
    if (isset($urlParts['path'])) {
        $pathContainsTimestamp = preg_match('/\.mp4\d{4}-\d{2}-\d{2}/', $urlParts['path']);
        echo "   Path contains timestamp concatenation: " . ($pathContainsTimestamp ? 'YES - PROBLEM CONFIRMED!' : 'NO') . "\n";
    }
    
    echo "8. Full Signed URL:\n";
    echo "   {$signedUrl}\n\n";
    
    echo "=== Debug Complete ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}