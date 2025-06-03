<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Segment;
use App\Jobs\DownloadTruefireSegmentV2;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Real Segment Data Test ===\n\n";

try {
    echo "1. Testing Database Connection...\n";
    
    // Test database connection
    $segmentCount = Segment::count();
    echo "   Found {$segmentCount} segments in database\n";
    
    if ($segmentCount === 0) {
        echo "   No segments found - creating test segment\n";
        
        // Create a test segment
        $testSegment = new Segment();
        $testSegment->id = 'test-segment-' . time();
        $testSegment->video = 'mp4:sample-video-file';
        $testSegment->save();
        
        echo "   Created test segment: {$testSegment->id}\n";
    } else {
        echo "   Using existing segments for testing\n";
    }
    
    echo "\n2. Testing Real Segment Signed URL Generation...\n";
    
    // Get first segment for testing
    $segment = Segment::first();
    
    if ($segment) {
        echo "   Testing segment ID: {$segment->id}\n";
        echo "   Video field: {$segment->video}\n";
        
        try {
            $signedUrl = $segment->getSignedUrl(3600);
            echo "   ✓ Signed URL generated successfully!\n";
            echo "   URL length: " . strlen($signedUrl) . " characters\n";
            echo "   URL starts with: " . substr($signedUrl, 0, 60) . "...\n";
            
            // Validate URL structure
            if (strpos($signedUrl, 'tfstream') !== false) {
                echo "   ✓ URL contains tfstream bucket\n";
            } else {
                echo "   ⚠ URL does not contain tfstream bucket\n";
            }
            
            if (strpos($signedUrl, 'X-Amz-Signature') !== false) {
                echo "   ✓ URL is properly signed\n";
            } else {
                echo "   ⚠ URL does not appear to be signed\n";
            }
            
        } catch (Exception $e) {
            echo "   ✗ Failed to generate signed URL: " . $e->getMessage() . "\n";
        }
    } else {
        echo "   ✗ No segments available for testing\n";
    }
    
    echo "\n3. Testing DownloadTruefireSegmentV2 Job Creation...\n";
    
    if ($segment) {
        try {
            // Create job instance (don't dispatch to avoid actual download)
            $job = new DownloadTruefireSegmentV2($segment->id);
            echo "   ✓ DownloadTruefireSegmentV2 job created successfully\n";
            echo "   Job segment ID: {$segment->id}\n";
            
            // Test job serialization
            $serialized = serialize($job);
            echo "   ✓ Job serialization successful\n";
            
        } catch (Exception $e) {
            echo "   ✗ Failed to create job: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n4. Testing AWS Environment Variables...\n";
    
    $awsVars = [
        'AWS_PROFILE' => env('AWS_PROFILE'),
        'AWS_SHARED_CREDENTIALS_FILE' => env('AWS_SHARED_CREDENTIALS_FILE'),
        'AWS_CONFIG_FILE' => env('AWS_CONFIG_FILE'),
        'AWS_DEFAULT_REGION' => env('AWS_DEFAULT_REGION'),
        'AWS_BUCKET' => env('AWS_BUCKET'),
    ];
    
    foreach ($awsVars as $key => $value) {
        echo "   {$key}: " . ($value ?: 'not set') . "\n";
    }
    
    echo "\n=== Test Complete ===\n";
    
} catch (Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
    echo "Error details: " . $e->getTraceAsString() . "\n";
}