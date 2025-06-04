<?php

/**
 * Queue Status Checker
 * 
 * Provides detailed queue status information
 */

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

class QueueStatusChecker
{
    public function checkStatus()
    {
        echo "=== DETAILED QUEUE STATUS ===\n\n";
        
        $this->showOverview();
        $this->showJobDetails();
        $this->showCacheStatus();
        $this->showRecommendations();
    }
    
    private function showOverview()
    {
        echo "OVERVIEW:\n";
        echo str_repeat("-", 30) . "\n";
        
        $totalJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();
        
        echo "Total active jobs: {$totalJobs}\n";
        echo "Failed jobs: {$failedJobs}\n";
        
        if ($totalJobs > 0) {
            $now = time();
            $pending = DB::table('jobs')->where('available_at', '<=', $now)->whereNull('reserved_at')->count();
            $delayed = DB::table('jobs')->where('available_at', '>', $now)->count();
            $processing = DB::table('jobs')->whereNotNull('reserved_at')->count();
            
            echo "  - Pending: {$pending}\n";
            echo "  - Delayed: {$delayed}\n";
            echo "  - Processing: {$processing}\n";
        }
        
        echo "\n";
    }
    
    private function showJobDetails()
    {
        echo "JOB DETAILS:\n";
        echo str_repeat("-", 30) . "\n";
        
        $jobs = DB::table('jobs')->get();
        
        if ($jobs->isEmpty()) {
            echo "No jobs in queue.\n\n";
            return;
        }
        
        // Group by job type
        $jobTypes = [];
        $now = time();
        
        foreach ($jobs as $job) {
            $payload = json_decode($job->payload, true);
            $jobClass = $payload['displayName'] ?? 'Unknown';
            
            if (!isset($jobTypes[$jobClass])) {
                $jobTypes[$jobClass] = [
                    'total' => 0,
                    'pending' => 0,
                    'delayed' => 0,
                    'processing' => 0,
                    'queues' => []
                ];
            }
            
            $jobTypes[$jobClass]['total']++;
            $jobTypes[$jobClass]['queues'][] = $job->queue;
            
            if ($job->reserved_at !== null) {
                $jobTypes[$jobClass]['processing']++;
            } elseif ($job->available_at > $now) {
                $jobTypes[$jobClass]['delayed']++;
            } else {
                $jobTypes[$jobClass]['pending']++;
            }
        }
        
        foreach ($jobTypes as $jobClass => $stats) {
            echo "Job Type: {$jobClass}\n";
            echo "  Total: {$stats['total']}\n";
            echo "  Pending: {$stats['pending']}\n";
            echo "  Delayed: {$stats['delayed']}\n";
            echo "  Processing: {$stats['processing']}\n";
            echo "  Queues: " . implode(', ', array_unique($stats['queues'])) . "\n\n";
        }
    }
    
    private function showCacheStatus()
    {
        echo "CACHE STATUS:\n";
        echo str_repeat("-", 30) . "\n";
        
        $rateLimitKey = 'download_rate_limit_v3';
        $currentRateLimit = Cache::get($rateLimitKey, 0);
        echo "Rate limit usage: {$currentRateLimit}/10\n";
        
        $bulkStats = Cache::get('bulk_download_stats', []);
        if (!empty($bulkStats)) {
            echo "Bulk download stats:\n";
            foreach ($bulkStats as $status => $count) {
                echo "  {$status}: {$count}\n";
            }
        }
        
        $processingSegments = Cache::get('bulk_processing_courses', []);
        echo "Currently processing segments: " . count($processingSegments) . "\n";
        
        echo "\n";
    }
    
    private function showRecommendations()
    {
        echo "RECOMMENDATIONS:\n";
        echo str_repeat("-", 30) . "\n";
        
        $totalJobs = DB::table('jobs')->count();
        $now = time();
        $delayedJobs = DB::table('jobs')->where('available_at', '>', $now)->count();
        
        if ($totalJobs === 0) {
            echo "✓ Queue is clean - no action needed.\n";
        } else {
            if ($delayedJobs > 0) {
                echo "⚠ Found {$delayedJobs} delayed jobs.\n";
                echo "  - These may be due to retry backoff strategies\n";
                echo "  - Use 'php artisan queue:clear' to remove all jobs\n";
                echo "  - Or use the cleanup tools to remove only delayed jobs\n";
            }
            
            if ($totalJobs > 100) {
                echo "⚠ Large number of jobs ({$totalJobs}) in queue.\n";
                echo "  - Consider if queue worker is running properly\n";
                echo "  - Check for stuck jobs\n";
            }
        }
        
        $failedJobs = DB::table('failed_jobs')->count();
        if ($failedJobs > 0) {
            echo "⚠ Found {$failedJobs} failed jobs.\n";
            echo "  - Use 'php artisan queue:prune-failed' to clean old failed jobs\n";
            echo "  - Or use cleanup tools for immediate removal\n";
        }
        
        echo "\n";
    }
}

// Run the checker
$checker = new QueueStatusChecker();
$checker->checkStatus();
