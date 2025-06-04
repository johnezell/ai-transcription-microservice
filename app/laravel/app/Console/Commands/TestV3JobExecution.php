<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\DownloadTruefireSegmentV3;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use Exception;

class TestV3JobExecution extends Command
{
    protected $signature = 'test:v3-job-execution';
    protected $description = 'Test actual V3 job execution to capture URL malformation';

    public function handle()
    {
        $this->info('=== V3 Job Execution Test ===');
        $this->newLine();

        try {
            // Create a mock segment object
            $mockSegment = (object) [
                'id' => 'test-segment-1748982286'
            ];
            
            $courseDir = 'test-course-dir';
            $courseId = 'test-course-123';
            
            $this->info('1. Creating V3 Job:');
            $this->line("   Segment ID: {$mockSegment->id}");
            $this->line("   Course Dir: {$courseDir}");
            $this->line("   Course ID: {$courseId}");
            
            // Create the job
            $job = new DownloadTruefireSegmentV3($mockSegment, $courseDir, $courseId);
            
            $this->info('2. Dispatching job to queue...');
            Queue::push($job);
            $this->line("   ✓ Job dispatched successfully");
            
            $this->info('3. Processing job immediately...');
            
            // Enable detailed logging
            Log::info("=== STARTING V3 JOB EXECUTION TEST ===");
            
            // Process the job manually to capture all output
            try {
                $job->handle();
                $this->line("   ✓ Job executed successfully");
            } catch (Exception $e) {
                $this->error("   ✗ Job execution failed: " . $e->getMessage());
                $this->line("   Error details: " . $e->getTraceAsString());
                
                // Check if the error contains the malformed URL pattern
                $errorMessage = $e->getMessage();
                if (strpos($errorMessage, 'test-segment-1748982286.mp42025-06-03') !== false) {
                    $this->error("   🔍 MALFORMED URL DETECTED IN ERROR MESSAGE!");
                    $this->line("   This confirms the URL malformation is happening during job execution");
                }
            }
            
            Log::info("=== V3 JOB EXECUTION TEST COMPLETE ===");
            
            $this->newLine();
            $this->info('4. Checking recent logs for URL patterns...');
            
            // Read recent log entries
            $logPath = storage_path('logs/laravel.log');
            if (file_exists($logPath)) {
                $logContent = file_get_contents($logPath);
                $recentLogs = substr($logContent, -5000); // Last 5KB of logs
                
                // Look for malformed URL patterns
                $malformedPattern = 'test-segment-1748982286.mp4\d{4}-\d{2}-\d{2}';
                if (preg_match('/' . $malformedPattern . '/', $recentLogs, $matches)) {
                    $this->error("   🔍 MALFORMED URL FOUND IN LOGS:");
                    $this->line("   Pattern: " . $matches[0]);
                } else {
                    $this->line("   No malformed URL patterns found in recent logs");
                }
                
                // Look for CloudFront-related errors
                if (strpos($recentLogs, 'CloudFront') !== false) {
                    $this->line("   CloudFront-related log entries found");
                } else {
                    $this->line("   No CloudFront-related log entries found");
                }
            } else {
                $this->line("   Log file not found");
            }
            
            $this->newLine();
            $this->info('=== Test Complete ===');
            
        } catch (Exception $e) {
            $this->error("ERROR: " . $e->getMessage());
            $this->line("Stack trace:");
            $this->line($e->getTraceAsString());
            return 1;
        }
        
        return 0;
    }
}