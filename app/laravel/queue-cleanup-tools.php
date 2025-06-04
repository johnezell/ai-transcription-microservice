<?php

/**
 * Comprehensive Queue Cleanup Tools
 * 
 * This script provides various queue cleanup options including
 * proper handling of delayed jobs.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

class QueueCleanupTools
{
    public function showMenu()
    {
        echo "=== QUEUE CLEANUP TOOLS ===\n\n";
        echo "1. Clear all jobs (including delayed)\n";
        echo "2. Clear specific queue\n";
        echo "3. Clear only delayed jobs\n";
        echo "4. Clear failed jobs\n";
        echo "5. Complete queue reset\n";
        echo "6. Show current status\n";
        echo "7. Clear bulk download cache\n";
        echo "0. Exit\n\n";
        
        $choice = readline("Select option: ");
        $this->handleChoice($choice);
    }
    
    private function handleChoice($choice)
    {
        switch ($choice) {
            case "1":
                $this->clearAllJobs();
                break;
            case "2":
                $this->clearSpecificQueue();
                break;
            case "3":
                $this->clearDelayedJobs();
                break;
            case "4":
                $this->clearFailedJobs();
                break;
            case "5":
                $this->completeQueueReset();
                break;
            case "6":
                $this->showStatus();
                break;
            case "7":
                $this->clearBulkDownloadCache();
                break;
            case "0":
                echo "Goodbye!\n";
                exit;
            default:
                echo "Invalid option. Please try again.\n\n";
                $this->showMenu();
        }
    }
    
    private function clearAllJobs()
    {
        echo "Clearing all jobs (including delayed)...\n";
        
        $count = DB::table('jobs')->count();
        echo "Found {$count} jobs to clear.\n";
        
        if ($count > 0) {
            $confirm = readline("Are you sure? (y/N): ");
            if (strtolower($confirm) === 'y') {
                DB::table('jobs')->delete();
                echo "All jobs cleared successfully!\n";
            } else {
                echo "Operation cancelled.\n";
            }
        }
        
        echo "\n";
        $this->showMenu();
    }
    
    private function clearSpecificQueue()
    {
        $queues = DB::table('jobs')->distinct()->pluck('queue');
        
        if ($queues->isEmpty()) {
            echo "No queues found.\n\n";
            $this->showMenu();
            return;
        }
        
        echo "Available queues:\n";
        foreach ($queues as $index => $queue) {
            echo ($index + 1) . ". {$queue}\n";
        }
        
        $choice = readline("Select queue number: ");
        $queueIndex = intval($choice) - 1;
        
        if (isset($queues[$queueIndex])) {
            $queueName = $queues[$queueIndex];
            $count = DB::table('jobs')->where('queue', $queueName)->count();
            
            echo "Found {$count} jobs in queue '{$queueName}'.\n";
            
            if ($count > 0) {
                $confirm = readline("Clear this queue? (y/N): ");
                if (strtolower($confirm) === 'y') {
                    DB::table('jobs')->where('queue', $queueName)->delete();
                    echo "Queue '{$queueName}' cleared successfully!\n";
                }
            }
        } else {
            echo "Invalid queue selection.\n";
        }
        
        echo "\n";
        $this->showMenu();
    }
    
    private function clearDelayedJobs()
    {
        echo "Clearing delayed jobs...\n";
        
        $now = time();
        $delayedCount = DB::table('jobs')->where('available_at', '>', $now)->count();
        
        echo "Found {$delayedCount} delayed jobs.\n";
        
        if ($delayedCount > 0) {
            $confirm = readline("Clear delayed jobs? (y/N): ");
            if (strtolower($confirm) === 'y') {
                DB::table('jobs')->where('available_at', '>', $now)->delete();
                echo "Delayed jobs cleared successfully!\n";
            }
        }
        
        echo "\n";
        $this->showMenu();
    }
    
    private function clearFailedJobs()
    {
        echo "Clearing failed jobs...\n";
        
        $count = DB::table('failed_jobs')->count();
        echo "Found {$count} failed jobs.\n";
        
        if ($count > 0) {
            $confirm = readline("Clear failed jobs? (y/N): ");
            if (strtolower($confirm) === 'y') {
                DB::table('failed_jobs')->delete();
                echo "Failed jobs cleared successfully!\n";
            }
        }
        
        echo "\n";
        $this->showMenu();
    }
    
    private function completeQueueReset()
    {
        echo "COMPLETE QUEUE RESET\n";
        echo "This will:\n";
        echo "- Clear all jobs (including delayed)\n";
        echo "- Clear all failed jobs\n";
        echo "- Clear bulk download cache\n";
        echo "- Reset rate limiting\n\n";
        
        $confirm = readline("Are you absolutely sure? (type 'RESET' to confirm): ");
        
        if ($confirm === 'RESET') {
            // Clear all jobs
            $jobCount = DB::table('jobs')->count();
            DB::table('jobs')->delete();
            
            // Clear failed jobs
            $failedCount = DB::table('failed_jobs')->count();
            DB::table('failed_jobs')->delete();
            
            // Clear cache
            $this->clearBulkDownloadCache(false);
            
            echo "Complete reset performed:\n";
            echo "- Cleared {$jobCount} jobs\n";
            echo "- Cleared {$failedCount} failed jobs\n";
            echo "- Cleared cache and rate limits\n";
            echo "Queue system reset successfully!\n";
        } else {
            echo "Reset cancelled.\n";
        }
        
        echo "\n";
        $this->showMenu();
    }
    
    private function showStatus()
    {
        echo "=== CURRENT QUEUE STATUS ===\n";
        
        $totalJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();
        
        echo "Total jobs: {$totalJobs}\n";
        echo "Failed jobs: {$failedJobs}\n";
        
        if ($totalJobs > 0) {
            $now = time();
            $pending = DB::table('jobs')->where('available_at', '<=', $now)->whereNull('reserved_at')->count();
            $delayed = DB::table('jobs')->where('available_at', '>', $now)->count();
            $processing = DB::table('jobs')->whereNotNull('reserved_at')->count();
            
            echo "Pending: {$pending}\n";
            echo "Delayed: {$delayed}\n";
            echo "Processing: {$processing}\n";
            
            // Queue distribution
            $queues = DB::table('jobs')->select('queue', DB::raw('count(*) as count'))->groupBy('queue')->get();
            echo "\nQueue distribution:\n";
            foreach ($queues as $queue) {
                echo "  {$queue->queue}: {$queue->count}\n";
            }
        }
        
        // Cache status
        $rateLimitKey = 'download_rate_limit_v3';
        $currentRateLimit = Cache::get($rateLimitKey, 0);
        echo "\nRate limit usage: {$currentRateLimit}/10\n";
        
        echo "\n";
        $this->showMenu();
    }
    
    private function clearBulkDownloadCache($showMenu = true)
    {
        echo "Clearing bulk download cache...\n";
        
        $cacheKeys = [
            'download_rate_limit_v3',
            'bulk_download_stats',
            'bulk_processing_courses'
        ];
        
        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
        
        // Clear course-specific caches (pattern-based)
        // Note: This is a simplified approach - in production you might want to track these keys
        echo "Cache cleared successfully!\n";
        
        if ($showMenu) {
            echo "\n";
            $this->showMenu();
        }
    }
}

// Run the tool
$tool = new QueueCleanupTools();
$tool->showMenu();
