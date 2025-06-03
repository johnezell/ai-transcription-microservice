<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== QUEUE CONFIGURATION DIAGNOSIS ===\n\n";

// 1. Check environment variables
echo "1. ENVIRONMENT VARIABLES:\n";
echo "   QUEUE_CONNECTION (env): " . env('QUEUE_CONNECTION', 'not set') . "\n";
echo "   DB_QUEUE_TABLE (env): " . env('DB_QUEUE_TABLE', 'not set') . "\n";
echo "   DB_QUEUE_CONNECTION (env): " . env('DB_QUEUE_CONNECTION', 'not set') . "\n";
echo "   DB_QUEUE (env): " . env('DB_QUEUE', 'not set') . "\n";

// 2. Check actual config values
echo "\n2. ACTUAL CONFIG VALUES:\n";
echo "   queue.default: " . config('queue.default') . "\n";
echo "   queue.connections.database.driver: " . config('queue.connections.database.driver') . "\n";
echo "   queue.connections.database.table: " . config('queue.connections.database.table') . "\n";
echo "   queue.connections.database.queue: " . config('queue.connections.database.queue') . "\n";
echo "   queue.connections.database.connection: " . config('queue.connections.database.connection') . "\n";

// 3. Check database connection
echo "\n3. DATABASE CONNECTION:\n";
try {
    $connection = DB::connection();
    echo "   Database connection: SUCCESS\n";
    echo "   Driver: " . $connection->getDriverName() . "\n";
    echo "   Database: " . $connection->getDatabaseName() . "\n";
} catch (Exception $e) {
    echo "   Database connection: FAILED - " . $e->getMessage() . "\n";
}

// 4. Check if jobs table exists
echo "\n4. JOBS TABLE:\n";
try {
    $hasJobsTable = Schema::hasTable('jobs');
    echo "   Jobs table exists: " . ($hasJobsTable ? 'YES' : 'NO') . "\n";
    
    if ($hasJobsTable) {
        $jobsCount = DB::table('jobs')->count();
        echo "   Jobs in queue: {$jobsCount}\n";
        
        // Show table structure
        $columns = Schema::getColumnListing('jobs');
        echo "   Table columns: " . implode(', ', $columns) . "\n";
    }
} catch (Exception $e) {
    echo "   Jobs table check: FAILED - " . $e->getMessage() . "\n";
}

// 5. Check failed jobs table
echo "\n5. FAILED JOBS TABLE:\n";
try {
    $hasFailedJobsTable = Schema::hasTable('failed_jobs');
    echo "   Failed jobs table exists: " . ($hasFailedJobsTable ? 'YES' : 'NO') . "\n";
    
    if ($hasFailedJobsTable) {
        $failedJobsCount = DB::table('failed_jobs')->count();
        echo "   Failed jobs count: {$failedJobsCount}\n";
    }
} catch (Exception $e) {
    echo "   Failed jobs table check: FAILED - " . $e->getMessage() . "\n";
}

// 6. Test queue manager
echo "\n6. QUEUE MANAGER:\n";
try {
    $queueManager = app('queue');
    $connection = $queueManager->connection();
    echo "   Queue manager: SUCCESS\n";
    echo "   Connection class: " . get_class($connection) . "\n";
    echo "   Connection name: " . $connection->getConnectionName() . "\n";
} catch (Exception $e) {
    echo "   Queue manager: FAILED - " . $e->getMessage() . "\n";
}

// 7. Check if we can switch to database driver
echo "\n7. FORCE DATABASE DRIVER TEST:\n";
try {
    config(['queue.default' => 'database']);
    $queueManager = app('queue');
    $connection = $queueManager->connection();
    echo "   Forced database driver: SUCCESS\n";
    echo "   Connection class: " . get_class($connection) . "\n";
} catch (Exception $e) {
    echo "   Forced database driver: FAILED - " . $e->getMessage() . "\n";
}

echo "\n=== DIAGNOSIS COMPLETE ===\n";