<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
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
            'priorityQueueStats' => $this->getPriorityQueueStatistics(),
            'jobTypeBreakdown' => $this->getJobTypeBreakdown(),
            'recentActivity' => $this->getRecentJobActivity(),
            'queueHealth' => $this->getQueueHealth(),
            'processingTimes' => $this->getProcessingTimes(),
            'segmentContext' => $this->getSegmentContextData(),
            'pipelineStatus' => $this->getPipelineStatus(),
            'workerStatus' => $this->getWorkerStatus(),
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
     * Retry a specific failed job with high priority.
     */
    public function retryFailedJob($jobId)
    {
        try {
            // Get the failed job
            $failedJob = DB::table('failed_jobs')->where('id', $jobId)->first();
            
            if (!$failedJob) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed job not found',
                ], 404);
            }

            // Parse the original payload
            $originalPayload = json_decode($failedJob->payload, true);
            
            if (!$originalPayload) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid job payload - cannot retry',
                ], 422);
            }

            // Extract job information
            $jobClass = $this->extractJobClass($originalPayload);
            $payloadData = $this->extractPayloadData($originalPayload, $failedJob->queue, null); // Failed jobs don't have priority column
            
            // Determine the appropriate high-priority queue based on job type
            $priorityQueue = $this->determinePriorityQueue($jobClass, $failedJob->queue);
            
            // Prepare new job payload with high priority
            $newPayload = $originalPayload;
            
            // Add retry metadata
            $newPayload['retry_metadata'] = [
                'is_retry' => true,
                'original_job_id' => $failedJob->id,
                'original_failure_time' => $failedJob->failed_at,
                'retry_time' => now()->toISOString(),
                'retry_priority' => 'high',
                'original_queue' => $failedJob->queue,
                'retry_queue' => $priorityQueue
            ];
            
            // Update priority in job data if it exists
            if (isset($newPayload['data']['processing'])) {
                $newPayload['data']['processing']['priority'] = 'high';
            }

            // Create new job entry in jobs table with HIGH PRIORITY
            $newJobId = DB::table('jobs')->insertGetId([
                'queue' => $priorityQueue,
                'payload' => json_encode($newPayload),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => time(),
                'created_at' => time(),
                'priority' => 10, // HIGH PRIORITY (Laravel uses higher numbers for higher priority)
            ]);

            // Remove the failed job from failed_jobs table
            DB::table('failed_jobs')->where('id', $jobId)->delete();

            \Log::info('Failed job retried with high priority', [
                'original_job_id' => $jobId,
                'new_job_id' => $newJobId,
                'job_class' => $jobClass,
                'original_queue' => $failedJob->queue,
                'retry_queue' => $priorityQueue,
                'context' => $payloadData['context'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Job retried successfully with high priority",
                'data' => [
                    'original_job_id' => $jobId,
                    'new_job_id' => $newJobId,
                    'job_class' => $jobClass,
                    'original_queue' => $failedJob->queue,
                    'retry_queue' => $priorityQueue,
                    'priority' => 'high',
                    'context' => $payloadData['context'] ?? null,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to retry job', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retry job: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Determine the appropriate queue for a job type (using single queue + priority).
     */
    private function determinePriorityQueue($jobClass, $originalQueue)
    {
        // TrueFire segment jobs use main queues with high priority
        if (str_contains($jobClass, 'TruefireSegment')) {
            if (str_contains($jobClass, 'Transcription')) {
                return 'transcription';
            } elseif (str_contains($jobClass, 'AudioExtraction')) {
                return 'audio-extraction';
            }
        }
        
        // Course-level jobs use main queues with high priority
        if (str_contains($jobClass, 'Course') && str_contains($jobClass, 'Transcription')) {
            return 'transcription';
        } elseif (str_contains($jobClass, 'Course') && str_contains($jobClass, 'AudioExtraction')) {
            return 'audio-extraction';
        }
        
        // Default queue based on original queue (strip priority suffixes)
        if (str_contains($originalQueue, 'transcription')) {
            return 'transcription';
        } elseif (str_contains($originalQueue, 'audio-extraction')) {
            return 'audio-extraction';
        }
        
        // Fallback: use audio extraction queue
        return 'audio-extraction';
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
                'reserved_at',
                'priority'
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
                    'payload_data' => $this->extractPayloadData($payload, $job->queue, $job->priority),
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
                    'payload_data' => $this->extractPayloadData($payload, $job->queue, null), // Failed jobs don't have priority column
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
     * Get worker status information based on actual job processing activity
     * (Not relying on unreliable supervisor socket monitoring)
     */
    private function getWorkerStatus()
    {
        try {
            // Focus on functional worker detection based on job processing activity
            $currentTime = Carbon::now();
            
            // Check for actively processing jobs (strong indicator workers are running)
            $processingJobs = DB::table('jobs')->whereNotNull('reserved_at')->count();
            
            // Check for recent job processing activity (last 5 minutes)
            $recentProcessing = DB::table('jobs')
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '>=', $currentTime->subMinutes(5)->timestamp)
                ->count();
            
            // Check for very recent job processing (last 2 minutes) 
            $veryRecentProcessing = DB::table('jobs')
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '>=', $currentTime->subMinutes(2)->timestamp)
                ->count();
                
            // Check for stuck jobs (jobs waiting over 30 minutes without processing)
            $stuckJobs = DB::table('jobs')
                ->where('created_at', '<', $currentTime->subMinutes(30)->timestamp)
                ->whereNull('reserved_at')
                ->count();
            
            // Check total pending jobs
            $pendingJobs = DB::table('jobs')->whereNull('reserved_at')->count();
            
            // Determine if workers are functioning based on processing activity
            $workersActive = false;
            $workerStatus = 'inactive';
            
            if ($processingJobs > 0) {
                // Jobs are currently being processed - workers definitely active
                $workersActive = true;
                $workerStatus = 'processing_jobs';
            } elseif ($veryRecentProcessing > 0) {
                // Recent processing activity (within 2 minutes) - workers likely active
                $workersActive = true;
                $workerStatus = 'recently_active';
            } elseif ($pendingJobs === 0) {
                // No pending jobs - workers may be idle but functioning
                $workersActive = true;
                $workerStatus = 'idle_no_jobs';
            } elseif ($stuckJobs > 0) {
                // Jobs stuck for 30+ minutes - workers likely not running
                $workersActive = false;
                $workerStatus = 'jobs_stuck';
            } else {
                // Jobs pending but recent activity unclear - uncertain status
                $workersActive = $recentProcessing > 0;
                $workerStatus = $workersActive ? 'uncertain_but_recent_activity' : 'uncertain_no_activity';
            }
            
            // Calculate health score based on actual performance metrics
            $healthScore = 100;
            if (!$workersActive) {
                $healthScore -= 60; // Major penalty for non-functional workers
            }
            if ($stuckJobs > 0) {
                $healthScore -= min($stuckJobs * 5, 30); // Penalty for stuck jobs
            }
            if ($pendingJobs > 10) {
                $healthScore -= min(($pendingJobs - 10) * 2, 10); // Small penalty for job backlog
            }
            
            $healthScore = max($healthScore, 0);
            $workerHealth = $healthScore >= 80 ? 'good' : ($healthScore >= 60 ? 'warning' : 'critical');
            
            // Return functional status information
            return [
                'workers_active' => $workersActive,
                'worker_status' => $workerStatus,
                'processing_jobs' => $processingJobs,
                'pending_jobs' => $pendingJobs,
                'stuck_jobs' => $stuckJobs,
                'recent_processing_activity' => $recentProcessing,
                'very_recent_activity' => $veryRecentProcessing,
                'health_score' => $healthScore,
                'worker_health' => $workerHealth,
                'status_description' => $this->getWorkerStatusDescription($workerStatus, $processingJobs, $pendingJobs, $stuckJobs),
                'last_checked' => $currentTime->toISOString(),
                'monitoring_method' => 'job_processing_activity', // Not supervisor socket monitoring
            ];
            
        } catch (\Exception $e) {
            return [
                'workers_active' => false,
                'worker_status' => 'error',
                'processing_jobs' => 0,
                'pending_jobs' => 0,
                'stuck_jobs' => 0,
                'recent_processing_activity' => 0,
                'very_recent_activity' => 0,
                'health_score' => 0,
                'worker_health' => 'critical',
                'status_description' => 'Error checking worker status: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'last_checked' => now()->toISOString(),
                'monitoring_method' => 'job_processing_activity',
            ];
        }
    }
    
    /**
     * Get human-readable description of worker status
     */
    private function getWorkerStatusDescription($status, $processingJobs, $pendingJobs, $stuckJobs)
    {
        switch ($status) {
            case 'processing_jobs':
                return "Workers are actively processing {$processingJobs} job(s)";
                
            case 'recently_active':
                return "Workers recently processed jobs (within last 2 minutes)";
                
            case 'idle_no_jobs':
                return "Workers appear to be running but no jobs in queue";
                
            case 'jobs_stuck':
                return "Workers may not be running - {$stuckJobs} job(s) stuck for 30+ minutes";
                
            case 'uncertain_but_recent_activity':
                return "Recent activity detected, workers likely running";
                
            case 'uncertain_no_activity':
                return "No recent processing activity detected - workers may not be running";
                
            case 'inactive':
                return "No worker activity detected";
                
            case 'error':
                return "Unable to determine worker status due to error";
                
            default:
                return "Unknown worker status: {$status}";
        }
    }

    /**
     * Get priority queue statistics (single queue + priority system).
     */
    private function getPriorityQueueStatistics()
    {
        $queues = ['audio-extraction', 'transcription'];
        
        $stats = [];
        foreach ($queues as $queue) {
            // Get priority breakdown within each queue
            $highPriorityJobs = DB::table('jobs')
                ->where('queue', $queue)
                ->where('priority', '>=', 5) // High priority (5-10)
                ->count();
                
            $normalPriorityJobs = DB::table('jobs')
                ->where('queue', $queue)
                ->where(function($query) {
                    $query->where('priority', '<', 5)
                          ->orWhereNull('priority'); // Default priority is 0
                })
                ->count();
            
            $totalJobs = $highPriorityJobs + $normalPriorityJobs;
            
            $processingJobs = DB::table('jobs')
                ->where('queue', $queue)
                ->whereNotNull('reserved_at')
                ->count();
            
            $stats[$queue] = [
                'total_jobs' => $totalJobs,
                'processing_jobs' => $processingJobs,
                'pending_jobs' => max(0, $totalJobs - $processingJobs),
                'high_priority_jobs' => $highPriorityJobs,
                'normal_priority_jobs' => $normalPriorityJobs,
                'queue_type' => $this->extractQueueType($queue),
            ];
            
            // Add virtual priority breakdowns for compatibility
            $stats[$queue . '-high'] = [
                'total_jobs' => $highPriorityJobs,
                'processing_jobs' => DB::table('jobs')
                    ->where('queue', $queue)
                    ->where('priority', '>=', 5)
                    ->whereNotNull('reserved_at')
                    ->count(),
                'pending_jobs' => $highPriorityJobs - DB::table('jobs')
                    ->where('queue', $queue)
                    ->where('priority', '>=', 5)
                    ->whereNotNull('reserved_at')
                    ->count(),
                'priority_level' => 'high',
                'queue_type' => $this->extractQueueType($queue),
            ];
            
            $stats[$queue . '-normal'] = [
                'total_jobs' => $normalPriorityJobs,
                'processing_jobs' => DB::table('jobs')
                    ->where('queue', $queue)
                    ->where(function($query) {
                        $query->where('priority', '<', 5)
                              ->orWhereNull('priority');
                    })
                    ->whereNotNull('reserved_at')
                    ->count(),
                'pending_jobs' => $normalPriorityJobs - DB::table('jobs')
                    ->where('queue', $queue)
                    ->where(function($query) {
                        $query->where('priority', '<', 5)
                              ->orWhereNull('priority');
                    })
                    ->whereNotNull('reserved_at')
                    ->count(),
                'priority_level' => 'normal',
                'queue_type' => $this->extractQueueType($queue),
            ];
        }

        // Log for debugging
        \Log::info('Priority Queue Statistics (Single Queue + Priority):', $stats);

        return $stats;
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
    private function extractPayloadData($payload, $queueName = null, $jobPriority = null)
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
            
            // TrueFire segment processing information
            if (isset($jobData['processing'])) {
                $processing = $jobData['processing'];
                $data['segment_id'] = $processing['segment_id'] ?? null;
                $data['course_id'] = $processing['course_id'] ?? null;
                $data['priority'] = $processing['priority'] ?? null;
                $data['status'] = $processing['status'] ?? null;
                $data['progress_percentage'] = $processing['progress_percentage'] ?? null;
            }
            
            // Extract transcription preset information
            if (isset($jobData['transcriptionPreset'])) {
                $data['transcription_preset'] = $jobData['transcriptionPreset'];
            }
            
            // Extract job options
            if (isset($jobData['jobOptions'])) {
                $options = $jobData['jobOptions'];
                $data['use_intelligent_detection'] = $options['use_intelligent_detection'] ?? false;
                $data['force_reextraction'] = $options['force_reextraction'] ?? false;
            }
            
            // Extract batch information
            if (isset($jobData['batch_id'])) {
                $data['batch_id'] = $jobData['batch_id'];
            }
            
            // Extract any ID fields
            foreach ($jobData as $key => $value) {
                if (str_ends_with($key, '_id') || $key === 'id') {
                    $data[$key] = $value;
                }
            }
        }

        // Extract priority from retry metadata if available (for retried jobs)
        if (!isset($data['priority']) && isset($payload['retry_metadata']['retry_priority'])) {
            $data['priority'] = $payload['retry_metadata']['retry_priority'];
        }

        // If no priority found yet, derive from context (job priority, queue name, etc.)
        if (!isset($data['priority'])) {
            $data['priority'] = $this->extractPriorityFromContext($payload, $queueName, $jobPriority);
        }

        // Ensure we always have a priority value
        $data['priority'] = $data['priority'] ?? 'normal';

        // Add context information if available
        if (isset($data['segment_id']) && isset($data['course_id'])) {
            $data['context'] = "Course {$data['course_id']}, Segment {$data['segment_id']}";
        }
        
        // Always set priority display
        $data['priority_display'] = strtoupper($data['priority']);

        // Add enhanced context based on job class
        $jobClass = $this->extractJobClass($payload);
        $data['job_description'] = $this->getJobDescription($jobClass, $data);
        $data['estimated_duration'] = $this->getEstimatedJobDuration($jobClass);

        return $data;
    }

    /**
     * Extract priority from various contexts (job priority, retry metadata, job type, etc.)
     */
    private function extractPriorityFromContext($payload, $queueName = null, $jobPriority = null)
    {
        // Method 1: Check actual job priority column (most reliable for new system!)
        if ($jobPriority !== null) {
            if ($jobPriority >= 5) {
                return 'high';
            } elseif ($jobPriority < 0) {
                return 'low';
            } else {
                return 'normal';
            }
        }
        
        // Method 2: Check for retry indicators (retried jobs should be high priority)
        if (isset($payload['retry_metadata']['is_retry'])) {
            return $payload['retry_metadata']['retry_priority'] ?? 'high';
        }
        
        // Method 3: For TrueFire jobs, check if it's a high-priority job type
        $jobClass = $this->extractJobClass($payload);
        
        // Course-level batch jobs are typically high priority
        if (str_contains($jobClass, 'ProcessCourse')) {
            return 'high';
        }
        
        // Method 4: Legacy queue name check (for old jobs still in system)
        if ($queueName) {
            if (str_contains($queueName, '-high')) {
                return 'high';
            }
            if (str_contains($queueName, '-low')) {
                return 'low';
            }
        }
        
        // Default priority
        return 'normal';
    }

    /**
     * Get human-readable job description.
     */
    private function getJobDescription($jobClass, $data)
    {
        switch ($jobClass) {
            case 'TruefireSegmentAudioExtractionJob':
                $context = $data['context'] ?? 'Unknown segment';
                $priority = $data['priority_display'] ?? 'NORMAL';
                return "Extract audio from {$context} [Priority: {$priority}]";
                
            case 'TruefireSegmentTranscriptionJob':
                $context = $data['context'] ?? 'Unknown segment';
                $priority = $data['priority_display'] ?? 'NORMAL';
                $preset = $data['transcription_preset'] ?? 'balanced';
                return "Transcribe {$context} using {$preset} preset [Priority: {$priority}]";
                
            case 'ProcessCourseAudioExtractionJob':
                $courseId = $data['course_id'] ?? 'Unknown';
                return "Process course {$courseId} audio extraction batch";
                
            case 'ProcessCourseTranscriptionJob':
                $courseId = $data['course_id'] ?? 'Unknown';
                return "Process course {$courseId} transcription batch";
                
            default:
                return $jobClass;
        }
    }

    /**
     * Get estimated job duration based on job type.
     */
    private function getEstimatedJobDuration($jobClass)
    {
        $durations = [
            'TruefireSegmentAudioExtractionJob' => '30s',
            'TruefireSegmentTranscriptionJob' => '2-5min',
            'ProcessCourseAudioExtractionJob' => '5-30min',
            'ProcessCourseTranscriptionJob' => '10-60min',
            'AudioExtractionTestJob' => '30s',
            'TranscriptionTestJob' => '1-3min',
        ];

        return $durations[$jobClass] ?? 'Unknown';
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
        $baseScore = 100;
        
        // Deduct points for issues
        $baseScore -= min($stuckJobs * 10, 50); // Max 50 points deduction
        $baseScore -= min($longRunningJobs * 5, 30); // Max 30 points deduction
        $baseScore -= min($highFailureQueues * 5, 20); // Max 20 points deduction
        
        return max($baseScore, 0);
    }

    /**
     * Get segment context data.
     */
    private function getSegmentContextData()
    {
        // Get segments currently being processed
        $processingSegments = DB::table('truefire_segment_processing')
            ->where('status', 'processing')
            ->orWhere('status', 'transcribing')
            ->get(['segment_id', 'course_id', 'status', 'priority', 'progress_percentage']);

        // Get recent completed segments (last 24 hours)
        $recentCompleted = DB::table('truefire_segment_processing')
            ->where('status', 'completed')
            ->where('updated_at', '>=', Carbon::now()->subDay())
            ->count();

        // Get failed segments
        $failedSegments = DB::table('truefire_segment_processing')
            ->where('status', 'failed')
            ->get(['segment_id', 'course_id', 'error_message', 'priority']);

        return [
            'processing_segments' => $processingSegments,
            'recent_completed_count' => $recentCompleted,
            'failed_segments' => $failedSegments,
            'total_segments_in_system' => DB::table('truefire_segment_processing')->count(),
        ];
    }

    /**
     * Get pipeline status showing the flow from audio extraction to transcription.
     */
    private function getPipelineStatus()
    {
        // Audio extraction pipeline
        $audioExtractionStats = $this->getQueuePipelineStats(['audio-extraction']);
        
        // Transcription pipeline
        $transcriptionStats = $this->getQueuePipelineStats(['transcription']);

        // Processing bottlenecks
        $bottlenecks = $this->detectPipelineBottlenecks();

        return [
            'audio_extraction' => $audioExtractionStats,
            'transcription' => $transcriptionStats,
            'bottlenecks' => $bottlenecks,
            'pipeline_efficiency' => $this->calculatePipelineEfficiency(),
        ];
    }

    /**
     * Extract priority level from queue name.
     */
    private function extractPriorityLevel($queueName)
    {
        if (str_contains($queueName, '-high')) return 'high';
        if (str_contains($queueName, '-low')) return 'low';
        return 'normal';
    }

    /**
     * Extract queue type from queue name.
     */
    private function extractQueueType($queueName)
    {
        if (str_contains($queueName, 'audio-extraction')) return 'audio_extraction';
        if (str_contains($queueName, 'transcription')) return 'transcription';
        return 'other';
    }

    /**
     * Get pipeline statistics for a group of queues.
     */
    private function getQueuePipelineStats($queues)
    {
        $totalJobs = 0;
        $processingJobs = 0;
        $priorityBreakdown = ['high' => 0, 'normal' => 0, 'low' => 0];

        foreach ($queues as $queue) {
            $queueJobs = DB::table('jobs')->where('queue', $queue)->count();
            $queueProcessing = DB::table('jobs')
                ->where('queue', $queue)
                ->whereNotNull('reserved_at')
                ->count();

            $totalJobs += $queueJobs;
            $processingJobs += $queueProcessing;

            $priority = $this->extractPriorityLevel($queue);
            $priorityBreakdown[$priority] += $queueJobs;
        }

        return [
            'total_jobs' => $totalJobs,
            'processing_jobs' => $processingJobs,
            'pending_jobs' => $totalJobs - $processingJobs,
            'priority_breakdown' => $priorityBreakdown,
        ];
    }

    /**
     * Detect pipeline bottlenecks.
     */
    private function detectPipelineBottlenecks()
    {
        $bottlenecks = [];

        // Check for high-priority jobs stuck (priority >= 5)
        $stuckHighPriorityJobs = DB::table('jobs')
            ->where('priority', '>=', 5)
            ->where('created_at', '<', Carbon::now()->subMinutes(10)->timestamp)
            ->whereNull('reserved_at')
            ->count();

        if ($stuckHighPriorityJobs > 0) {
            $bottlenecks[] = [
                'type' => 'stuck_high_priority',
                'count' => $stuckHighPriorityJobs,
                'description' => 'High priority jobs waiting more than 10 minutes'
            ];
        }

        // Check for queue imbalance
        $audioJobs = DB::table('jobs')->where('queue', 'audio-extraction')->count();
        $transcriptionJobs = DB::table('jobs')->where('queue', 'transcription')->count();

        if ($audioJobs > 0 && $transcriptionJobs / max($audioJobs, 1) > 3) {
            $bottlenecks[] = [
                'type' => 'transcription_backlog',
                'audio_jobs' => $audioJobs,
                'transcription_jobs' => $transcriptionJobs,
                'description' => 'Transcription queue backing up relative to audio extraction'
            ];
        }

        return $bottlenecks;
    }

    /**
     * Calculate overall pipeline efficiency.
     */
    private function calculatePipelineEfficiency()
    {
        $totalPendingJobs = DB::table('jobs')->whereNull('reserved_at')->count();
        $totalProcessingJobs = DB::table('jobs')->whereNotNull('reserved_at')->count();
        $totalJobs = $totalPendingJobs + $totalProcessingJobs;

        if ($totalJobs === 0) {
            return 100; // No jobs = 100% efficient
        }

        $processingRatio = ($totalProcessingJobs / $totalJobs) * 100;
        return round($processingRatio, 1);
    }
}