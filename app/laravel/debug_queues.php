<?php
require_once '/var/www/bootstrap/app.php';

$app = $app ?? require_once '/var/www/bootstrap/app.php';

echo "=== Queue Breakdown ===\n";
$queueCounts = DB::table('jobs')
    ->select('queue', DB::raw('count(*) as count'))
    ->groupBy('queue')
    ->get();

foreach($queueCounts as $row) {
    echo $row->queue . ': ' . $row->count . "\n";
}

echo "\n=== High Priority Jobs ===\n";
$highPriorityJobs = DB::table('jobs')
    ->where('queue', 'audio-extraction-high')
    ->get(['id', 'created_at', 'reserved_at', 'payload']);

if ($highPriorityJobs->count() == 0) {
    echo "No jobs in audio-extraction-high queue\n";
} else {
    foreach($highPriorityJobs as $job) {
        $status = $job->reserved_at ? 'PROCESSING' : 'QUEUED';
        $age = time() - $job->created_at;
        echo "Job ID: " . $job->id . " - Status: " . $status . " - Age: " . $age . "s\n";
        
        // Check payload to see job class
        $payload = json_decode($job->payload, true);
        $jobClass = $payload['displayName'] ?? $payload['job'] ?? 'Unknown';
        echo "  Job Type: " . $jobClass . "\n";
    }
}

echo "\n=== Worker Processes Check ===\n";
// Try to see if workers are actually running
exec('pgrep -f "queue:work"', $output);
echo "Queue worker processes: " . count($output) . "\n";
foreach($output as $pid) {
    echo "  PID: " . $pid . "\n";
} 