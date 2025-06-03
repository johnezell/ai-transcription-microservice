<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use App\Jobs\DownloadTruefireSegmentV2;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Laravel Queue Verification Test ===\n\n";

// 1. Check queue configuration
echo "1. Queue Configuration:\n";
echo "   Driver: " . config('queue.default') . "\n";
echo "   Connection: " . config('queue.connections.database.driver') . "\n";
echo "   Table: " . config('queue.connections.database.table') . "\n";
echo "   Database: " . config('database.connections.sqlite.database') . "\n\n";

// 2. Check database connection
echo "2. Database Connection Test:\n";
try {
    DB::connection()->getPdo();
    echo "   ✅ Database connection successful\n";
    
    // Check if jobs table exists
    $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'");
    if (count($tables) > 0) {
        echo "   ✅ Jobs table exists\n";
    } else {
        echo "   ❌ Jobs table missing - run: php artisan migrate\n";
    }
} catch (Exception $e) {
    echo "   ❌ Database connection failed: " . $e->getMessage() . "\n";
}
echo "\n";

// 3. Check current job count
echo "3. Current Queue Status:\n";
try {
    $pendingJobs = DB::table('jobs')->count();
    $failedJobs = DB::table('failed_jobs')->count();
    echo "   Pending jobs: $pendingJobs\n";
    echo "   Failed jobs: $failedJobs\n";
    
    if ($pendingJobs > 0) {
        echo "   Recent jobs:\n";
        $recentJobs = DB::table('jobs')
            ->select('id', 'queue', 'created_at')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
        
        foreach ($recentJobs as $job) {
            echo "     - Job #{$job->id} on '{$job->queue}' queue (created: {$job->created_at})\n";
        }
    }
} catch (Exception $e) {
    echo "   ❌ Error checking job status: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. Test job dispatch
echo "4. Test Job Dispatch:\n";
try {
    // Create a test job
    $testJob = new DownloadTruefireSegmentV2(
        segmentId: 'test-segment-' . time(),
        videoUrl: 'https://example.com/test-video.mp4',
        outputPath: '/tmp/test-output',
        courseId: 999,
        segmentNumber: 1
    );
    
    // Dispatch to the downloads queue
    Queue::push($testJob, '', 'downloads');
    
    echo "   ✅ Test job dispatched successfully\n";
    
    // Check if job was added to database
    $newJobCount = DB::table('jobs')->count();
    echo "   Jobs in queue after dispatch: $newJobCount\n";
    
    if ($newJobCount > $pendingJobs) {
        echo "   ✅ Job successfully added to database queue\n";
    } else {
        echo "   ❌ Job may not have been queued properly\n";
    }
    
} catch (Exception $e) {
    echo "   ❌ Error dispatching test job: " . $e->getMessage() . "\n";
}
echo "\n";

// 5. Worker status check
echo "5. Queue Worker Status:\n";
echo "   To check if workers are running, use:\n";
echo "   docker ps | grep worker\n";
echo "   \n";
echo "   To view worker logs:\n";
echo "   docker logs download-worker-1\n";
echo "   docker logs download-worker-2\n";
echo "   (etc. for workers 3-5)\n";
echo "\n";

// 6. Manual worker command
echo "6. Manual Worker Commands:\n";
echo "   Start a single worker:\n";
echo "   docker-compose exec laravel php artisan queue:work --queue=downloads --sleep=3 --tries=3\n";
echo "   \n";
echo "   Process one job and exit:\n";
echo "   docker-compose exec laravel php artisan queue:work --once --queue=downloads\n";
echo "\n";

// 7. Monitoring commands
echo "7. Monitoring Commands:\n";
echo "   Check queue status:\n";
echo "   docker-compose exec laravel php artisan queue:monitor\n";
echo "   \n";
echo "   Clear failed jobs:\n";
echo "   docker-compose exec laravel php artisan queue:flush\n";
echo "   \n";
echo "   Restart workers:\n";
echo "   docker-compose exec laravel php artisan queue:restart\n";
echo "\n";

echo "=== Test Complete ===\n";
echo "If jobs are being queued but not processed, ensure queue workers are running.\n";
echo "Use: docker-compose -f docker-compose.yml -f docker-compose.workers.yml up -d\n";