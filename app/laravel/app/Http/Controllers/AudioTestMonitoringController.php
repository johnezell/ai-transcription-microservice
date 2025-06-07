<?php

namespace App\Http\Controllers;

use App\Services\AudioTestMetricsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AudioTestMonitoringController extends Controller
{
    /**
     * The metrics service instance.
     *
     * @var AudioTestMetricsService
     */
    protected $metricsService;

    /**
     * Create a new controller instance.
     *
     * @param AudioTestMetricsService $metricsService
     */
    public function __construct(AudioTestMetricsService $metricsService)
    {
        $this->metricsService = $metricsService;
        
        // Only apply middleware if we're in a web context
        if (app()->runningInConsole() === false) {
            $this->middleware('auth');
        }
    }

    /**
     * Get overall system performance metrics.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSystemMetrics(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'days' => 'sometimes|integer|min:1|max:90',
                'refresh' => 'sometimes|boolean'
            ]);

            $days = $request->get('days', 30);
            $refresh = $request->boolean('refresh', false);

            // Clear cache if refresh requested
            if ($refresh) {
                $this->metricsService->clearCache();
            }

            $metrics = $this->metricsService->getSystemMetrics($days);

            return response()->json([
                'success' => true,
                'data' => $metrics,
                'meta' => [
                    'generated_at' => now()->toISOString(),
                    'cache_status' => $refresh ? 'refreshed' : 'cached',
                    'period_days' => $days
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to get system metrics', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'request_params' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve system metrics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get processing performance statistics.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getProcessingStats(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'days' => 'sometimes|integer|min:1|max:90',
                'quality_level' => 'sometimes|string|in:fast,balanced,high,premium'
            ]);

            $days = $request->get('days', 7);
            $qualityLevel = $request->get('quality_level');

            $cacheKey = "processing_stats_{$days}_{$qualityLevel}";
            
            $stats = Cache::remember($cacheKey, 900, function () use ($days, $qualityLevel) {
                $startDate = now()->subDays($days);
                
                $query = DB::table('transcription_logs')
                    ->where('is_test_extraction', true)
                    ->where('created_at', '>=', $startDate)
                    ->whereNotNull('total_processing_duration_seconds');

                if ($qualityLevel) {
                    $query->where('test_quality_level', $qualityLevel);
                }

                $stats = $query->selectRaw('
                    test_quality_level,
                    COUNT(*) as total_processed,
                    AVG(total_processing_duration_seconds) as avg_processing_time,
                    MIN(total_processing_duration_seconds) as min_processing_time,
                    MAX(total_processing_duration_seconds) as max_processing_time,
                    STDDEV(total_processing_duration_seconds) as stddev_processing_time,
                    AVG(audio_file_size) as avg_file_size,
                    AVG(audio_duration_seconds) as avg_audio_duration,
                    SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_count
                ')
                ->groupBy('test_quality_level')
                ->get();

                return $stats->map(function ($stat) {
                    return [
                        'quality_level' => $stat->test_quality_level,
                        'total_processed' => $stat->total_processed,
                        'completed_count' => $stat->completed_count,
                        'failed_count' => $stat->failed_count,
                        'success_rate' => $stat->total_processed > 0 
                            ? round(($stat->completed_count / $stat->total_processed) * 100, 2) 
                            : 0,
                        'avg_processing_time' => round($stat->avg_processing_time, 2),
                        'min_processing_time' => round($stat->min_processing_time, 2),
                        'max_processing_time' => round($stat->max_processing_time, 2),
                        'stddev_processing_time' => round($stat->stddev_processing_time ?? 0, 2),
                        'avg_file_size_mb' => round(($stat->avg_file_size ?? 0) / (1024 * 1024), 2),
                        'avg_audio_duration' => round($stat->avg_audio_duration ?? 0, 2),
                        'efficiency_ratio' => $stat->avg_audio_duration > 0 
                            ? round($stat->avg_processing_time / $stat->avg_audio_duration, 2) 
                            : 0
                    ];
                })->keyBy('quality_level');
            });

            return response()->json([
                'success' => true,
                'data' => $stats,
                'meta' => [
                    'period_days' => $days,
                    'quality_level_filter' => $qualityLevel,
                    'generated_at' => now()->toISOString()
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to get processing stats', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve processing statistics'
            ], 500);
        }
    }

    /**
     * Get real-time queue status and health.
     *
     * @return JsonResponse
     */
    public function getQueueStatus(): JsonResponse
    {
        try {
            $cacheKey = 'audio_test_queue_status';
            
            $queueStatus = Cache::remember($cacheKey, 60, function () {
                // Get current queue statistics
                $pendingJobs = DB::table('jobs')
                    ->where('queue', 'like', '%audio%')
                    ->count();

                $failedJobs = DB::table('failed_jobs')
                    ->where('payload', 'like', '%Audio%')
                    ->where('failed_at', '>=', now()->subDay())
                    ->count();

                $processingJobs = DB::table('transcription_logs')
                    ->where('is_test_extraction', true)
                    ->where('status', 'processing')
                    ->count();

                $recentCompletions = DB::table('transcription_logs')
                    ->where('is_test_extraction', true)
                    ->where('status', 'completed')
                    ->where('completed_at', '>=', now()->subHour())
                    ->count();

                $avgWaitTime = DB::table('transcription_logs')
                    ->where('is_test_extraction', true)
                    ->where('audio_extraction_started_at', '>=', now()->subHour())
                    ->whereNotNull('started_at')
                    ->whereNotNull('audio_extraction_started_at')
                    ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, started_at, audio_extraction_started_at)) as avg_wait')
                    ->value('avg_wait') ?? 0;

                // Determine health status
                $health = 'healthy';
                if ($failedJobs > 10) {
                    $health = 'critical';
                } elseif ($pendingJobs > 50 || $avgWaitTime > 300) {
                    $health = 'warning';
                }

                return [
                    'pending_jobs' => $pendingJobs,
                    'failed_jobs_24h' => $failedJobs,
                    'processing_jobs' => $processingJobs,
                    'completed_last_hour' => $recentCompletions,
                    'avg_wait_time_seconds' => round($avgWaitTime, 2),
                    'health_status' => $health,
                    'throughput_per_hour' => $recentCompletions,
                    'queue_utilization' => $this->calculateQueueUtilization(),
                    'estimated_completion_time' => $this->estimateCompletionTime($pendingJobs),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $queueStatus,
                'meta' => [
                    'timestamp' => now()->toISOString(),
                    'cache_ttl_seconds' => 60
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get queue status', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve queue status'
            ], 500);
        }
    }

    /**
     * Get user activity and usage patterns.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getUserActivity(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'days' => 'sometimes|integer|min:1|max:90'
            ]);

            $days = $request->get('days', 30);
            $cacheKey = "user_activity_{$days}";

            $activity = Cache::remember($cacheKey, 1800, function () use ($days) {
                $startDate = now()->subDays($days);

                // Get user statistics
                $userStats = DB::table('audio_test_batches')
                    ->where('created_at', '>=', $startDate)
                    ->selectRaw('
                        COUNT(DISTINCT user_id) as unique_users,
                        COUNT(*) as total_batches,
                        AVG(total_segments) as avg_segments_per_batch,
                        SUM(total_segments) as total_segments_processed
                    ')
                    ->first();

                // Get top users by activity
                $topUsers = DB::table('audio_test_batches')
                    ->join('users', 'audio_test_batches.user_id', '=', 'users.id')
                    ->where('audio_test_batches.created_at', '>=', $startDate)
                    ->selectRaw('
                        users.id,
                        users.name,
                        COUNT(*) as batch_count,
                        SUM(total_segments) as total_segments,
                        AVG(total_segments) as avg_segments_per_batch,
                        MAX(audio_test_batches.created_at) as last_activity
                    ')
                    ->groupBy('users.id', 'users.name')
                    ->orderBy('batch_count', 'desc')
                    ->limit(10)
                    ->get();

                // Get daily activity
                $dailyActivity = DB::table('audio_test_batches')
                    ->where('created_at', '>=', $startDate)
                    ->selectRaw('
                        DATE(created_at) as date,
                        COUNT(*) as batches_created,
                        COUNT(DISTINCT user_id) as unique_users,
                        SUM(total_segments) as segments_processed
                    ')
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get();

                // Get usage patterns
                $qualityPreferences = DB::table('transcription_logs')
                    ->where('is_test_extraction', true)
                    ->where('created_at', '>=', $startDate)
                    ->selectRaw('test_quality_level, COUNT(*) as count')
                    ->groupBy('test_quality_level')
                    ->orderBy('count', 'desc')
                    ->get();

                return [
                    'summary' => [
                        'unique_users' => $userStats->unique_users ?? 0,
                        'total_batches' => $userStats->total_batches ?? 0,
                        'avg_segments_per_batch' => round($userStats->avg_segments_per_batch ?? 0, 1),
                        'total_segments_processed' => $userStats->total_segments_processed ?? 0,
                    ],
                    'top_users' => $topUsers->toArray(),
                    'daily_activity' => $dailyActivity->toArray(),
                    'quality_preferences' => $qualityPreferences->toArray(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $activity,
                'meta' => [
                    'period_days' => $days,
                    'generated_at' => now()->toISOString()
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to get user activity', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user activity data'
            ], 500);
        }
    }

    /**
     * Get performance trends over time.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPerformanceTrends(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'days' => 'sometimes|integer|min:7|max:90'
            ]);

            $days = $request->get('days', 30);
            $trends = $this->metricsService->getPerformanceTrends($days);

            return response()->json([
                'success' => true,
                'data' => $trends,
                'meta' => [
                    'period_days' => $days,
                    'generated_at' => now()->toISOString()
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to get performance trends', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve performance trends'
            ], 500);
        }
    }

    /**
     * Get resource usage metrics.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getResourceUsage(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'days' => 'sometimes|integer|min:1|max:90'
            ]);

            $days = $request->get('days', 7);
            $cacheKey = "resource_usage_{$days}";

            $usage = Cache::remember($cacheKey, 600, function () use ($days) {
                $startDate = now()->subDays($days);

                $resourceStats = DB::table('transcription_logs')
                    ->where('is_test_extraction', true)
                    ->where('created_at', '>=', $startDate)
                    ->selectRaw('
                        COUNT(*) as total_jobs,
                        SUM(total_processing_duration_seconds) as total_processing_time,
                        SUM(audio_file_size) as total_audio_size,
                        AVG(total_processing_duration_seconds) as avg_processing_time,
                        AVG(audio_file_size) as avg_file_size,
                        MAX(total_processing_duration_seconds) as max_processing_time,
                        MIN(total_processing_duration_seconds) as min_processing_time
                    ')
                    ->first();

                $concurrentStats = DB::table('audio_test_batches')
                    ->where('created_at', '>=', $startDate)
                    ->selectRaw('
                        AVG(concurrent_jobs) as avg_concurrent_jobs,
                        MAX(concurrent_jobs) as max_concurrent_jobs,
                        MIN(concurrent_jobs) as min_concurrent_jobs,
                        SUM(total_segments) as total_segments_processed
                    ')
                    ->first();

                return [
                    'processing' => [
                        'total_jobs' => $resourceStats->total_jobs ?? 0,
                        'total_processing_hours' => round(($resourceStats->total_processing_time ?? 0) / 3600, 2),
                        'avg_processing_time_seconds' => round($resourceStats->avg_processing_time ?? 0, 2),
                        'max_processing_time_seconds' => round($resourceStats->max_processing_time ?? 0, 2),
                        'min_processing_time_seconds' => round($resourceStats->min_processing_time ?? 0, 2),
                    ],
                    'storage' => [
                        'total_audio_size_gb' => round(($resourceStats->total_audio_size ?? 0) / (1024 * 1024 * 1024), 2),
                        'avg_file_size_mb' => round(($resourceStats->avg_file_size ?? 0) / (1024 * 1024), 2),
                    ],
                    'concurrency' => [
                        'avg_concurrent_jobs' => round($concurrentStats->avg_concurrent_jobs ?? 0, 1),
                        'max_concurrent_jobs' => $concurrentStats->max_concurrent_jobs ?? 0,
                        'min_concurrent_jobs' => $concurrentStats->min_concurrent_jobs ?? 0,
                        'total_segments_processed' => $concurrentStats->total_segments_processed ?? 0,
                    ],
                    'efficiency' => [
                        'cpu_utilization_estimate' => $this->estimateCpuUtilization($resourceStats),
                        'memory_usage_estimate_gb' => $this->estimateMemoryUsage($resourceStats),
                        'throughput_jobs_per_hour' => $this->calculateThroughput($resourceStats, $days),
                    ]
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $usage,
                'meta' => [
                    'period_days' => $days,
                    'generated_at' => now()->toISOString()
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request parameters',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to get resource usage', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve resource usage data'
            ], 500);
        }
    }

    /**
     * Get system alerts and warnings.
     *
     * @return JsonResponse
     */
    public function getAlerts(): JsonResponse
    {
        try {
            $alerts = Cache::get('audio_test_active_alerts', []);
            
            // Add real-time alert checks
            $realtimeAlerts = $this->checkRealtimeAlerts();
            $allAlerts = array_merge($alerts, $realtimeAlerts);

            // Sort by severity
            usort($allAlerts, function ($a, $b) {
                $severityOrder = ['critical' => 3, 'warning' => 2, 'info' => 1];
                return ($severityOrder[$b['severity']] ?? 0) - ($severityOrder[$a['severity']] ?? 0);
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'alerts' => $allAlerts,
                    'summary' => [
                        'total' => count($allAlerts),
                        'critical' => count(array_filter($allAlerts, fn($a) => $a['severity'] === 'critical')),
                        'warning' => count(array_filter($allAlerts, fn($a) => $a['severity'] === 'warning')),
                        'info' => count(array_filter($allAlerts, fn($a) => $a['severity'] === 'info')),
                    ]
                ],
                'meta' => [
                    'generated_at' => now()->toISOString(),
                    'last_check' => now()->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get alerts', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve system alerts'
            ], 500);
        }
    }

    /**
     * Calculate queue utilization percentage.
     *
     * @return float
     */
    protected function calculateQueueUtilization(): float
    {
        $maxConcurrentJobs = config('queue.connections.database.max_concurrent_jobs', 10);
        $currentProcessing = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('status', 'processing')
            ->count();

        return round(($currentProcessing / $maxConcurrentJobs) * 100, 1);
    }

    /**
     * Estimate completion time for pending jobs.
     *
     * @param int $pendingJobs
     * @return string
     */
    protected function estimateCompletionTime(int $pendingJobs): string
    {
        if ($pendingJobs === 0) {
            return 'No pending jobs';
        }

        $avgProcessingTime = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('completed_at', '>=', now()->subHours(6))
            ->whereNotNull('total_processing_duration_seconds')
            ->avg('total_processing_duration_seconds') ?? 60;

        $maxConcurrentJobs = config('queue.connections.database.max_concurrent_jobs', 5);
        $estimatedSeconds = ceil($pendingJobs / $maxConcurrentJobs) * $avgProcessingTime;

        if ($estimatedSeconds < 60) {
            return "{$estimatedSeconds} seconds";
        } elseif ($estimatedSeconds < 3600) {
            return round($estimatedSeconds / 60, 1) . " minutes";
        } else {
            return round($estimatedSeconds / 3600, 1) . " hours";
        }
    }

    /**
     * Estimate CPU utilization based on processing stats.
     *
     * @param object $stats
     * @return float
     */
    protected function estimateCpuUtilization($stats): float
    {
        $totalJobs = $stats->total_jobs ?? 0;
        $totalProcessingTime = $stats->total_processing_time ?? 0;
        
        if ($totalJobs === 0) {
            return 0;
        }

        // Rough estimate: assume 70% CPU usage during processing
        $avgProcessingTime = $totalProcessingTime / $totalJobs;
        return round(min(100, $avgProcessingTime * 0.7), 1);
    }

    /**
     * Estimate memory usage based on processing stats.
     *
     * @param object $stats
     * @return float
     */
    protected function estimateMemoryUsage($stats): float
    {
        $totalJobs = $stats->total_jobs ?? 0;
        
        // Rough estimate: 500MB per concurrent job
        return round($totalJobs * 0.5, 2);
    }

    /**
     * Calculate throughput in jobs per hour.
     *
     * @param object $stats
     * @param int $days
     * @return float
     */
    protected function calculateThroughput($stats, int $days): float
    {
        $totalJobs = $stats->total_jobs ?? 0;
        $hours = $days * 24;
        
        return $hours > 0 ? round($totalJobs / $hours, 2) : 0;
    }

    /**
     * Check for real-time alerts.
     *
     * @return array
     */
    protected function checkRealtimeAlerts(): array
    {
        $alerts = [];

        // Check for stuck jobs
        $stuckJobs = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('status', 'processing')
            ->where('audio_extraction_started_at', '<', now()->subMinutes(30))
            ->count();

        if ($stuckJobs > 0) {
            $alerts[] = [
                'type' => 'stuck_jobs',
                'severity' => 'warning',
                'message' => "{$stuckJobs} jobs appear to be stuck in processing state",
                'count' => $stuckJobs,
                'detected_at' => now()->toISOString()
            ];
        }

        // Check for high failure rate in last hour
        $recentJobs = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        $recentFailures = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentJobs > 0) {
            $failureRate = ($recentFailures / $recentJobs) * 100;
            if ($failureRate > 20) {
                $alerts[] = [
                    'type' => 'high_failure_rate',
                    'severity' => 'critical',
                    'message' => "High failure rate detected: {$failureRate}% in the last hour",
                    'failure_rate' => round($failureRate, 1),
                    'detected_at' => now()->toISOString()
                ];
            }
        }

        return $alerts;
    }
}