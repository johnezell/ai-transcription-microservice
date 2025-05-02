<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MonitorQueueWorker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:monitor-status {--fix : Fix issues by clearing stuck jobs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor the queue worker status and check for stuck jobs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Monitoring queue worker status...");
        
        // Log this command execution
        Log::info("Queue monitor command executed");
        
        // Get jobs in the queue
        $jobs = DB::table('jobs')->get();
        $jobCount = $jobs->count();
        
        $this->info("{$jobCount} jobs found in queue");
        
        // Show job details
        if ($jobCount > 0) {
            $table = [];
            foreach ($jobs as $job) {
                $payload = json_decode($job->payload, true);
                $commandName = $payload['displayName'] ?? 'Unknown';
                $data = isset($payload['data']['command']) ? unserialize($payload['data']['command']) : null;
                $videoId = null;
                
                if ($data && method_exists($data, 'getVideoId')) {
                    $videoId = $data->getVideoId();
                } elseif ($data && isset($data->video) && method_exists($data->video, 'getKey')) {
                    $videoId = $data->video->getKey();
                }
                
                $table[] = [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'command' => $commandName,
                    'video_id' => $videoId,
                    'attempts' => $job->attempts,
                    'created_at' => date('Y-m-d H:i:s', $job->created_at)
                ];
            }
            
            $this->table(
                ['ID', 'Queue', 'Command', 'Video ID', 'Attempts', 'Created At'],
                $table
            );
            
            // Fix issues if requested
            if ($this->option('fix')) {
                $this->info("Clearing all jobs from the queue...");
                DB::table('jobs')->truncate();
                $this->info("Queue cleared successfully");
                
                // Start a new queue worker
                $this->call('queue:work', [
                    '--once' => true
                ]);
                
                $this->info("Started a new queue worker process");
            }
        }
        
        // Check failed jobs
        $failedJobs = DB::table('failed_jobs')->get();
        $failedCount = $failedJobs->count();
        
        $this->info("{$failedCount} failed jobs found");
        
        if ($failedCount > 0) {
            $failedTable = [];
            foreach ($failedJobs as $job) {
                $payload = json_decode($job->payload, true);
                $commandName = $payload['displayName'] ?? 'Unknown';
                
                $failedTable[] = [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'command' => $commandName,
                    'failed_at' => $job->failed_at,
                    'exception' => Str::limit($job->exception, 50)
                ];
            }
            
            $this->table(
                ['ID', 'Queue', 'Command', 'Failed At', 'Exception'],
                $failedTable
            );
            
            // Clear failed jobs if requested
            if ($this->option('fix')) {
                $this->info("Clearing all failed jobs...");
                DB::table('failed_jobs')->truncate();
                $this->info("Failed jobs cleared successfully");
            }
        }
        
        return 0;
    }
} 