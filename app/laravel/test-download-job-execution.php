<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use App\Models\Segment;
use App\Jobs\DownloadTruefireSegmentV2;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Download Job Execution Test ===\n\n";

try {
    echo "1. Testing Database Connection and Segment Retrieval...\n";
    
    // Get a test segment
    $segment = Segment::first();
    
    if (!$segment) {
        echo "   ✗ No segments found in database\n";
        exit(1);
    }
    
    echo "   ✓ Found segment ID: {$segment->id}\n";
    echo "   Video field: {$segment->video}\n";
    
    echo "\n2. Testing Signed URL Generation...\n";
    
    try {
        $signedUrl = $segment->getSignedUrl(3600);
        echo "   ✓ Signed URL generated successfully\n";
        echo "   URL length: " . strlen($signedUrl) . " characters\n";
        
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
        exit(1);
    }
    
    echo "\n3. Testing DownloadTruefireSegmentV2 Job Creation...\n";
    
    try {
        // Create proper job parameters
        $courseDir = "test-course-" . time();
        $courseId = 1; // Test course ID
        
        // Create job instance with correct parameters
        $job = new DownloadTruefireSegmentV2($segment, $courseDir, $signedUrl, $courseId);
        echo "   ✓ DownloadTruefireSegmentV2 job created successfully\n";
        echo "   Job parameters:\n";
        echo "     - Segment ID: {$segment->id}\n";
        echo "     - Course Dir: {$courseDir}\n";
        echo "     - Course ID: {$courseId}\n";
        echo "     - Signed URL length: " . strlen($signedUrl) . " chars\n";
        
        // Test job serialization
        $serialized = serialize($job);
        echo "   ✓ Job serialization successful\n";
        
    } catch (Exception $e) {
        echo "   ✗ Failed to create job: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    echo "\n4. Testing Job Dispatch (Sync Mode)...\n";
    
    try {
        // Set queue to sync for immediate execution
        config(['queue.default' => 'sync']);
        
        echo "   Dispatching job in sync mode for immediate testing...\n";
        
        // Dispatch the job
        DownloadTruefireSegmentV2::dispatch($segment, $courseDir, $signedUrl, $courseId);
        
        echo "   ✓ Job dispatched and executed successfully\n";
        
        // Check if file was downloaded
        $filename = "{$segment->id}.mp4";
        $filePath = "{$courseDir}/{$filename}";
        
        if (Storage::disk('local')->exists($filePath)) {
            $fileSize = Storage::disk('local')->size($filePath);
            echo "   ✓ File downloaded successfully: {$filePath}\n";
            echo "   File size: " . number_format($fileSize) . " bytes\n";
            
            // Clean up test file
            Storage::disk('local')->delete($filePath);
            echo "   ✓ Test file cleaned up\n";
        } else {
            echo "   ⚠ File not found after download: {$filePath}\n";
        }
        
    } catch (Exception $e) {
        echo "   ✗ Job execution failed: " . $e->getMessage() . "\n";
        echo "   Error details: " . $e->getTraceAsString() . "\n";
    }
    
    echo "\n5. Testing AWS Environment Configuration...\n";
    
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
    
    echo "\n=== Download Job Test Complete ===\n";
    
} catch (Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
    echo "Error details: " . $e->getTraceAsString() . "\n";
    exit(1);
}