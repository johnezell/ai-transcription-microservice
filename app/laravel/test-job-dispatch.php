<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use App\Jobs\DownloadTruefireSegmentV2;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Testing job dispatch mechanism...\n";

try {
    // Test 1: Check database connection
    echo "1. Testing database connection...\n";
    $jobsCount = DB::table('jobs')->count();
    $failedJobsCount = DB::table('failed_jobs')->count();
    echo "   Jobs in queue: {$jobsCount}\n";
    echo "   Failed jobs: {$failedJobsCount}\n";
    
    // Test 2: Check queue configuration
    echo "\n2. Checking queue configuration...\n";
    $queueDriver = config('queue.default');
    echo "   Queue driver: {$queueDriver}\n";
    
    if ($queueDriver === 'database') {
        $queueTable = config('queue.connections.database.table');
        echo "   Queue table: {$queueTable}\n";
        $queueName = config('queue.connections.database.queue');
        echo "   Default queue name: {$queueName}\n";
    }
    
    // Test 3: Try to dispatch a test job
    echo "\n3. Testing job dispatch...\n";
    
    // Create a mock segment object
    $segment = new stdClass();
    $segment->id = 'test-segment-' . time();
    
    // Try to dispatch the job
    DownloadTruefireSegmentV2::dispatch($segment, 'test-course-dir', 'http://test.url', 'test-course-id');
    echo "   Job dispatched successfully!\n";
    
    // Check if job was added to queue
    $newJobsCount = DB::table('jobs')->count();
    echo "   Jobs in queue after dispatch: {$newJobsCount}\n";
    
    if ($newJobsCount > $jobsCount) {
        echo "   ✓ Job was successfully added to the queue!\n";
        
        // Get the job details
        $job = DB::table('jobs')->latest('id')->first();
        echo "   Job ID: {$job->id}\n";
        echo "   Queue: {$job->queue}\n";
        echo "   Attempts: {$job->attempts}\n";
        echo "   Created at: " . date('Y-m-d H:i:s', $job->created_at) . "\n";
        
    } else {
        echo "   ✗ Job was NOT added to the queue!\n";
    }
    
    // Test 4: Check if queue worker is needed
    echo "\n4. Queue worker status...\n";
    echo "   To process jobs, you need to run: php artisan queue:work\n";
    echo "   Or for testing: php artisan queue:work --once\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\nTest completed.\n";