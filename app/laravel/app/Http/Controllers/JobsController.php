<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Carbon\Carbon;

class JobsController extends Controller
{
    /**
     * Display the jobs visualization page.
     */
    public function index()
    {
        $data = [
            'activeJobs' => $this->getActiveJobs(),
            'failedJobs' => $this->getFailedJobs(),
            'jobBatches' => $this->getJobBatches(),
            'queueStats' => $this->getQueueStatistics(),
            'jobTypeBreakdown' => $this->getJobTypeBreakdown(),
            'recentActivity' => $this->getRecentJobActivity(),
            'queueHealth' => $this->getQueueHealth(),
            'processingTimes' => $this->getProcessingTimes(),
        ];

        return Inertia::render('Jobs/Index', $data);
    }

    /**
     * Prune all completed jobs from the jobs table.
     */
    public function pruneAll()
    {
        try {
            // Get count before deletion for response
            $completedJobsCount = DB::table('jobs')
                ->whereNull('reserved_at')
                ->where('available_at', '<=', time())
                ->count();

            // Delete completed jobs (jobs that are not reserved and are available)
            $deletedCount = DB::table('jobs')
                ->whereNull('reserved_at')
                ->where('available_at', '<=', time())
                ->delete();

            // Get updated statistics
            $remainingJobs = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();

            return response()->json([
                'success' => true,
                'message' => "Successfully pruned {$deletedCount} completed jobs",
                'deleted_count' => $deletedCount,
                'remaining_active_jobs' => $remainingJobs,
                'failed_jobs' => $failedJobs,
                'total_jobs' => $remainingJobs + $failedJobs,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to prune jobs: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear all failed jobs from the failed_jobs table.
     */
    public function clearFailed()
    {
        try {
            // Get count before deletion for response
            $failedJobsCount = DB::table('failed_jobs')->count();

            // Delete all failed jobs
            $deletedCount = DB::table('failed_jobs')->delete();

            // Get updated statistics
            $activeJobs = DB::table('jobs')->count();

            return response()->json([
                'success' => true,
                'message' => "Successfully cleared {$deletedCount} failed jobs",
                'deleted_count' => $deletedCount,
                'remaining_active_jobs' => $activeJobs,
                'failed_jobs' => 0,
                'total_jobs' => $activeJobs,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear failed jobs: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get active jobs from the jobs table.
     */
    private function getActiveJobs()
    {
        return DB::table('jobs')
            ->select([
                'id',
                'queue',
                'payload',
                'attempts',
                'created_at',
                'available_at',
                'reserved_at'
            ])
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);
                
                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'job_class' => $this->extractJobClass($payload),
                    'attempts' => $job->attempts,
                    'status' => $this->getJobStatus($job),
                    'created_at' => Carbon::createFromTimestamp($job->created_at)->toISOString(),
                    'available_at' => Carbon::createFromTimestamp($job->available_at)->toISOString(),
                    'reserved_at' => $job->reserved_at ? Carbon::createFromTimestamp($job->reserved_at)->toISOString() : null,
                    'payload_data' => $this->extractPayloadData($payload),
                    'wait_time' => $this->calculateWaitTime($job),
                    'processing_time' => $this->calculateProcessingTime($job),
                ];
            });
    }

    /**
     * Get failed jobs from the failed_jobs table.
     */
    private function getFailedJobs()
    {
        return DB::table('failed_jobs')
            ->select([
                'id',
                'uuid',
                'connection',
                'queue',
                'payload',
                'exception',
                'failed_at'
            ])
            ->orderBy('failed_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);
                
                return [
                    'id' => $job->id,
                    'uuid' => $job->uuid,
                    'connection' => $job->connection,
                    'queue' => $job->queue,
                    'job_class' => $this->extractJobClass($payload),
                    'failed_at' => Carbon::parse($job->failed_at)->toISOString(),
                    'exception' => $this->formatException($job->exception),
                    'payload_data' => $this->extractPayloadData($payload),
                ];
            });
    }

    /**
     * Get job batches from the job_batches table.
     */
    private function getJobBatches()
    {
        return DB::table('job_batches')
            ->select([
                'id',
                'name',
                'total_jobs',
                'pending_jobs',
                'failed_jobs',
                'failed_job_ids',
                'options',
                'cancelled_at',
                'created_at',
                'finished_at'
            ])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($batch) {
                $completedJobs = $batch->total_jobs - $batch->pending_jobs - $batch->failed_jobs;
                $progressPercentage = $batch->total_jobs > 0 
                    ? round(($completedJobs / $batch->total_jobs) * 100, 2) 
                    : 0;

                return [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'total_jobs' => $batch->total_jobs,
                    'pending_jobs' => $batch->pending_jobs,
                    'failed_jobs' => $batch->failed_jobs,
                    'completed_jobs' => $completedJobs,
                    'progress_percentage' => $progressPercentage,
                    'status' => $this->getBatchStatus($batch),
                    'failed_job_ids' => json_decode($batch->failed_job_ids, true) ?? [],
                    'options' => json_decode($batch->options, true) ?? [],
                    'cancelled_at' => $batch->cancelled_at ? Carbon::createFromTimestamp($batch->cancelled_at)->toISOString() : null,
                    'created_at' => Carbon::createFromTimestamp($batch->created_at)->toISOString(),
                    'finished_at' => $batch->finished_at ? Carbon::createFromTimestamp($batch->finished_at)->toISOString() : null,
                    'duration' => $this->calculateBatchDuration($batch),
                ];
            });
    }

    /**
     * Get queue statistics.
     */
    private function getQueueStatistics()
    {
        $activeJobsCount = DB::table('jobs')->count();
        $failedJobsCount = DB::table('failed_jobs')->count();
        $batchesCount = DB::table('job_batches')->count();
        
        // Count jobs by status
        $processingJobs = DB::table('jobs')->whereNotNull('reserved_at')->count();
        $pendingJobs = $activeJobsCount - $processingJobs;
        
        // Queue-specific stats
        $queueStats = DB::table('jobs')
            ->select('queue', DB::raw('count(*) as count'))
            ->groupBy('queue')
            ->get()
            ->keyBy('queue')
            ->map(fn($stat) => $stat->count);

        return [
            'total_active_jobs' => $activeJobsCount,
            'total_failed_jobs' => $failedJobsCount,
            'total_batches' => $batchesCount,
            'pending_jobs' => $pendingJobs,
            'processing_jobs' => $processingJobs,
            'queue_breakdown' => $queueStats,
            'success_rate' => $this->calculateSuccessRate(),
            'average_processing_time' => $this->getAverageProcessingTime(),
        ];
    }

    /**
     * Get job type breakdown by parsing payloads.
     */
    private function getJobTypeBreakdown()
    {
        // Active jobs breakdown
        $activeJobTypes = DB::table('jobs')
            ->get(['payload'])
            ->groupBy(function ($job) {
                $payload = json_decode($job->payload, true);
                return $this->extractJobClass($payload);
            })
            ->map(fn($jobs) => $jobs->count());

        // Failed jobs breakdown
        $failedJobTypes = DB::table('failed_jobs')
            ->get(['payload'])
            ->groupBy(function ($job) {
                $payload = json_decode($job->payload, true);
                return $this->extractJobClass($payload);
            })
            ->map(fn($jobs) => $jobs->count());

        return [
            'active_jobs' => $activeJobTypes,
            'failed_jobs' => $failedJobTypes,
            'combined' => $activeJobTypes->mergeRecursive($failedJobTypes)
                ->map(fn($count) => is_array($count) ? array_sum($count) : $count),
        ];
    }

    /**
     * Get recent job activity (last 24 hours).
     */
    private function getRecentJobActivity()
    {
        $since = Carbon::now()->subDay()->timestamp;
        
        // Recent active jobs - SQLite compatible
        $recentActive = DB::table('jobs')
            ->where('created_at', '>=', $since)
            ->selectRaw('
                strftime("%Y-%m-%d %H:00:00", datetime(created_at, "unixepoch")) as hour,
                COUNT(*) as count
            ')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        // Recent failed jobs - SQLite compatible
        $recentFailed = DB::table('failed_jobs')
            ->where('failed_at', '>=', Carbon::now()->subDay())
            ->selectRaw('
                strftime("%Y-%m-%d %H:00:00", failed_at) as hour,
                COUNT(*) as count
            ')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        return [
            'active_jobs_timeline' => $recentActive,
            'failed_jobs_timeline' => $recentFailed,
            'total_jobs_last_24h' => DB::table('jobs')->where('created_at', '>=', $since)->count(),
            'total_failed_last_24h' => DB::table('failed_jobs')->where('failed_at', '>=', Carbon::now()->subDay())->count(),
        ];
    }

    /**
     * Get queue health metrics.
     */
    private function getQueueHealth()
    {
        $oldJobsThreshold = Carbon::now()->subHours(2)->timestamp;
        $stuckJobs = DB::table('jobs')
            ->where('created_at', '<', $oldJobsThreshold)
            ->whereNull('reserved_at')
            ->count();

        $longRunningJobs = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', Carbon::now()->subHour()->timestamp)
            ->count();

        $highFailureQueues = DB::table('failed_jobs')
            ->select('queue', DB::raw('count(*) as failures'))
            ->where('failed_at', '>=', Carbon::now()->subDay())
            ->groupBy('queue')
            ->having('failures', '>', 5)
            ->get();

        return [
            'stuck_jobs' => $stuckJobs,
            'long_running_jobs' => $longRunningJobs,
            'high_failure_queues' => $highFailureQueues,
            'health_score' => $this->calculateHealthScore($stuckJobs, $longRunningJobs, $highFailureQueues->count()),
        ];
    }

    /**
     * Get processing time statistics.
     */
    private function getProcessingTimes()
    {
        // This is estimated based on available data
        $processingJobs = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->get(['reserved_at'])
            ->map(function ($job) {
                return Carbon::now()->timestamp - $job->reserved_at;
            });

        return [
            'current_processing_times' => $processingJobs->toArray(),
            'average_current_processing' => $processingJobs->avg(),
            'longest_current_processing' => $processingJobs->max(),
        ];
    }

    /**
     * Extract job class name from payload.
     */
    private function extractJobClass($payload)
    {
        if (!is_array($payload)) {
            return 'Unknown';
        }

        // Laravel job payload structure
        if (isset($payload['displayName'])) {
            return $payload['displayName'];
        }

        if (isset($payload['job'])) {
            return class_basename($payload['job']);
        }

        if (isset($payload['data']['commandName'])) {
            return class_basename($payload['data']['commandName']);
        }

        return 'Unknown';
    }

    /**
     * Extract relevant data from job payload.
     */
    private function extractPayloadData($payload)
    {
        if (!is_array($payload)) {
            return [];
        }

        $data = [];
        
        // Extract common useful information
        if (isset($payload['data'])) {
            $jobData = $payload['data'];
            
            // Look for model information
            if (isset($jobData['video'])) {
                $data['video_id'] = $jobData['video']['id'] ?? null;
            }
            
            if (isset($jobData['course'])) {
                $data['course_id'] = $jobData['course']['id'] ?? null;
            }
            
            // Extract any ID fields
            foreach ($jobData as $key => $value) {
                if (str_ends_with($key, '_id') || $key === 'id') {
                    $data[$key] = $value;
                }
            }
        }

        return $data;
    }

    /**
     * Determine job status based on job data.
     */
    private function getJobStatus($job)
    {
        if ($job->reserved_at) {
            return 'processing';
        }
        
        if ($job->available_at > time()) {
            return 'delayed';
        }
        
        return 'queued';
    }

    /**
     * Calculate wait time for a job.
     */
    private function calculateWaitTime($job)
    {
        if ($job->reserved_at) {
            return $job->reserved_at - $job->created_at;
        }
        
        return time() - $job->created_at;
    }

    /**
     * Calculate processing time for a job.
     */
    private function calculateProcessingTime($job)
    {
        if ($job->reserved_at) {
            return time() - $job->reserved_at;
        }
        
        return null;
    }

    /**
     * Format exception for display.
     */
    private function formatException($exception)
    {
        $lines = explode("\n", $exception);
        return [
            'message' => $lines[0] ?? 'Unknown error',
            'full_trace' => $exception,
            'line_count' => count($lines),
        ];
    }

    /**
     * Get batch status.
     */
    private function getBatchStatus($batch)
    {
        if ($batch->cancelled_at) {
            return 'cancelled';
        }
        
        if ($batch->finished_at) {
            return $batch->failed_jobs > 0 ? 'completed_with_failures' : 'completed';
        }
        
        if ($batch->pending_jobs === 0 && $batch->failed_jobs === 0) {
            return 'completed';
        }
        
        return 'processing';
    }

    /**
     * Calculate batch duration.
     */
    private function calculateBatchDuration($batch)
    {
        if (!$batch->finished_at) {
            return time() - $batch->created_at;
        }
        
        return $batch->finished_at - $batch->created_at;
    }

    /**
     * Calculate overall success rate.
     */
    private function calculateSuccessRate()
    {
        $totalJobs = DB::table('jobs')->count() + DB::table('failed_jobs')->count();
        
        if ($totalJobs === 0) {
            return 100;
        }
        
        $failedJobs = DB::table('failed_jobs')->count();
        return round((($totalJobs - $failedJobs) / $totalJobs) * 100, 2);
    }

    /**
     * Get average processing time (estimated).
     */
    private function getAverageProcessingTime()
    {
        // This is a simplified calculation
        // In a real implementation, you might track job completion times
        $processingJobs = DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->get(['reserved_at']);
            
        if ($processingJobs->isEmpty()) {
            return 0;
        }
        
        $totalTime = $processingJobs->sum(function ($job) {
            return time() - $job->reserved_at;
        });
        
        return round($totalTime / $processingJobs->count(), 2);
    }

    /**
     * Calculate health score.
     */
    private function calculateHealthScore($stuckJobs, $longRunningJobs, $highFailureQueues)
    {
        $score = 100;
        
        // Deduct points for issues
        $score -= min($stuckJobs * 5, 30); // Max 30 points for stuck jobs
        $score -= min($longRunningJobs * 3, 20); // Max 20 points for long running
        $score -= min($highFailureQueues * 10, 50); // Max 50 points for high failure queues
        
        return max($score, 0);
    }
}