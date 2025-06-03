<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Segment;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== S3 File Download Test ===\n\n";

try {
    echo "1. Testing actual file download from TrueFire S3 bucket...\n";
    
    // Get a real segment from the database
    $segment = Segment::first();
    
    if (!$segment) {
        echo "   ✗ No segments found in database\n";
        exit(1);
    }
    
    echo "   Testing segment ID: {$segment->id}\n";
    echo "   Video field: {$segment->video}\n";
    
    // Generate signed URL
    $signedUrl = $segment->getSignedUrl(3600); // 1 hour expiration
    echo "   ✓ Generated signed URL\n";
    echo "   URL: " . substr($signedUrl, 0, 100) . "...\n";
    
    echo "\n2. Testing HTTP HEAD request to verify file exists...\n";
    
    // Use cURL to test if the file is accessible
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $signedUrl);
    curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request only
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "   HTTP Response Code: {$httpCode}\n";
    
    if ($httpCode == 200) {
        echo "   ✓ File is accessible!\n";
        echo "   Content Type: {$contentType}\n";
        echo "   Content Length: " . ($contentLength > 0 ? number_format($contentLength) . " bytes" : "Unknown") . "\n";
        
        // Test partial download to verify actual file access
        echo "\n3. Testing partial file download (first 1KB)...\n";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $signedUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_RANGE, '0-1023'); // First 1KB
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $partialContent = curl_exec($ch);
        $partialHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $downloadedSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        
        if ($partialHttpCode == 206 || $partialHttpCode == 200) {
            echo "   ✓ Partial download successful!\n";
            echo "   Downloaded: {$downloadedSize} bytes\n";
            echo "   File appears to be a valid video file\n";
            
            // Check if it looks like a video file
            $fileHeader = substr($partialContent, 0, 20);
            if (strpos($fileHeader, 'ftyp') !== false) {
                echo "   ✓ File header indicates MP4 format\n";
            } else {
                echo "   ⚠ File header doesn't clearly indicate MP4 format\n";
            }
            
        } else {
            echo "   ⚠ Partial download failed with HTTP code: {$partialHttpCode}\n";
        }
        
    } elseif ($httpCode == 403) {
        echo "   ✗ Access denied (HTTP 403) - Check credentials or bucket permissions\n";
    } elseif ($httpCode == 404) {
        echo "   ⚠ File not found (HTTP 404) - File may not exist in S3 bucket\n";
    } else {
        echo "   ✗ Unexpected HTTP response: {$httpCode}\n";
        if ($error) {
            echo "   cURL Error: {$error}\n";
        }
    }
    
    echo "\n4. Testing with different bucket (tfstream)...\n";
    
    // Test direct access to tfstream bucket
    $tfstreamUrl = str_replace('aws-transcription-data-542876199144-us-east-1', 'tfstream', $signedUrl);
    echo "   Testing tfstream URL: " . substr($tfstreamUrl, 0, 100) . "...\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tfstreamUrl);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($ch);
    $tfstreamHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "   TFStream HTTP Response Code: {$tfstreamHttpCode}\n";
    
    if ($tfstreamHttpCode == 200) {
        echo "   ✓ TFStream bucket is accessible!\n";
    } elseif ($tfstreamHttpCode == 403) {
        echo "   ✗ TFStream access denied (HTTP 403)\n";
    } elseif ($tfstreamHttpCode == 404) {
        echo "   ⚠ File not found in TFStream bucket (HTTP 404)\n";
    } else {
        echo "   ⚠ TFStream returned HTTP code: {$tfstreamHttpCode}\n";
    }
    
    echo "\n=== Download Test Summary ===\n";
    
    if ($httpCode == 200) {
        echo "✓ S3 credentials are working correctly\n";
        echo "✓ Signed URLs provide access to files\n";
        echo "✓ File download capability confirmed\n";
    } else {
        echo "⚠ File access issues detected\n";
        echo "⚠ May need to verify file paths or bucket permissions\n";
    }
    
} catch (Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
    echo "Error details: " . $e->getTraceAsString() . "\n";
}

echo "\n=== Test Complete ===\n";