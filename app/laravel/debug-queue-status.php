<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Queue Status Debug ===" . PHP_EOL;

// Check total jobs
$totalJobs = DB::table('jobs')->count();
echo "Total jobs in queue: " . $totalJobs . PHP_EOL;

// Check jobs by queue
$queueCounts = DB::table('jobs')
    ->select('queue', DB::raw('count(*) as count'))
    ->groupBy('queue')
    ->get();

echo "Jobs by queue:" . PHP_EOL;
foreach ($queueCounts as $queue) {
    echo "  - Queue '{$queue->queue}': {$queue->count} jobs" . PHP_EOL;
}

// Check a sample job payload
$sampleJob = DB::table('jobs')->first();
if ($sampleJob) {
    $payload = json_decode($sampleJob->payload, true);
    echo "Sample job details:" . PHP_EOL;
    echo "  - Queue: " . $sampleJob->queue . PHP_EOL;
    echo "  - Job Class: " . ($payload['displayName'] ?? 'Unknown') . PHP_EOL;
    echo "  - Attempts: " . $sampleJob->attempts . PHP_EOL;
    echo "  - Available at: " . date('Y-m-d H:i:s', $sampleJob->available_at) . PHP_EOL;
}

echo "=== End Debug ===" . PHP_EOL;