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

echo "=== Queue Download Job Test ===\n\n";

try {
    echo "1. Setting up Queue Configuration...\n";
    
    // Set queue to database for testing
    config(['queue.default' => 'database']);
    
    echo "   ✓ Queue configured to use database driver\n";
    
    echo "\n2. Getting Test Segment...\n";
    
    // Get a test segment
    $segment = Segment::first();
    
    if (!$segment) {
        echo "   ✗ No segments found in database\n";
        exit(1);
    }
    
    echo "   ✓ Found segment ID: {$segment->id}\n";
    echo "   Video field: {$segment->video}\n";
    
    echo "\n3. Generating Signed URL...\n";
    
    try {
        $signedUrl = $segment->getSignedUrl(3600);
        echo "   ✓ Signed URL generated successfully\n";
        echo "   URL length: " . strlen($signedUrl) . " characters\n";
        
    } catch (Exception $e) {
        echo "   ✗ Failed to generate signed URL: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    echo "\n4. Dispatching Job to Queue...\n";
    
    try {
        // Create proper job parameters
        $courseDir = "queue-test-course-" . time();
        $courseId = 2; // Test course ID
        
        // Dispatch job to queue
        $job = DownloadTruefireSegmentV2::dispatch($segment, $courseDir, $signedUrl, $courseId);
        
        echo "   ✓ Job dispatched to downloads queue successfully\n";
        echo "   Job parameters:\n";
        echo "     - Segment ID: {$segment->id}\n";
        echo "     - Course Dir: {$courseDir}\n";
        echo "     - Course ID: {$courseId}\n";
        
    } catch (Exception $e) {
        echo "   ✗ Failed to dispatch job: " . $e->getMessage() . "\n";
        exit(1);
    }
    
    echo "\n5. Checking Queue Status...\n";
    
    // Check if job is in queue
    $queueSize = Queue::size('downloads');
    echo "   Queue size (downloads): {$queueSize}\n";
    
    if ($queueSize > 0) {
        echo "   ✓ Job successfully added to queue\n";
        echo "\n6. Processing Queue Job...\n";
        echo "   Note: Run 'docker-compose exec laravel php artisan queue:work --once --queue=downloads' to process the job\n";
        
        // Show the command to process the job
        echo "\n   Command to process the queued job:\n";
        echo "   docker-compose exec laravel php artisan queue:work --once --queue=downloads\n";
        
    } else {
        echo "   ⚠ No jobs found in queue - job may have been processed immediately\n";
    }
    
    echo "\n7. Queue Configuration Summary...\n";
    
    $queueConfig = [
        'Default Driver' => config('queue.default'),
        'Downloads Queue' => config('queue.connections.database.queue', 'default'),
        'Database Connection' => config('queue.connections.database.connection'),
        'Table' => config('queue.connections.database.table', 'jobs'),
    ];
    
    foreach ($queueConfig as $key => $value) {
        echo "   {$key}: " . ($value ?: 'not set') . "\n";
    }
    
    echo "\n=== Queue Download Job Test Complete ===\n";
    echo "\nNext Steps:\n";
    echo "1. Process the queued job with: docker-compose exec laravel php artisan queue:work --once --queue=downloads\n";
    echo "2. Check Laravel logs for job execution details\n";
    echo "3. Verify downloaded file in storage/app/{$courseDir}/\n";
    
} catch (Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
    echo "Error details: " . $e->getTraceAsString() . "\n";
    exit(1);
}