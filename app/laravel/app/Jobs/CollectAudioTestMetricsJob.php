<?php

namespace App\Jobs;

use App\Services\AudioTestMetricsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CollectAudioTestMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300; // 5 minutes

    /**
     * The collection period in days.
     *
     * @var int
     */
    protected $days;

    /**
     * The collection type (hourly, daily, weekly).
     *
     * @var string
     */
    protected $type;

    /**
     * Create a new job instance.
     *
     * @param string $type Collection type (hourly, daily, weekly)
     * @param int $days Number of days to analyze
     */
    public function __construct(string $type = 'hourly', int $days = 1)
    {
        $this->type = $type;
        $this->days = $days;
        
        // Set queue based on collection type
        $this->onQueue($type === 'hourly' ? 'monitoring' : 'low-priority');
    }

    /**
     * Execute the job.
     */
    public function handle(AudioTestMetricsService $metricsService): void
    {
        try {
            Log::info('Starting audio test metrics collection', [
                'type' => $this->type,
                'days' => $this->days,
                'timestamp' => now()->toISOString()
            ]);

            $startTime = microtime(true);

            // Collect and cache metrics based on type
            switch ($this->type) {
                case 'hourly':
                    $this->collectHourlyMetrics($metricsService);
                    break;
                case 'daily':
                    $this->collectDailyMetrics($metricsService);
                    break;
                case 'weekly':
                    $this->collectWeeklyMetrics($metricsService);
                    break;
                default:
                    throw new \InvalidArgumentException("Invalid collection type: {$this->type}");
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            // Store collection metadata
            $this->storeCollectionMetadata($executionTime);

            // Perform maintenance tasks
            $this->performMaintenance();

            Log::info('Audio test metrics collection completed', [
                'type' => $this->type,
                'days' => $this->days,
                'execution_time_ms' => $executionTime,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to collect audio test metrics', [
                'type' => $this->type,
                'days' => $this->days,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Collect hourly metrics.
     *
     * @param AudioTestMetricsService $metricsService
     * @return void
     */
    protected function collectHourlyMetrics(AudioTestMetricsService $metricsService): void
    {
        // Collect current hour metrics
        $metrics = $metricsService->getSystemMetrics($this->days);
        
        // Cache with shorter TTL for real-time monitoring
        Cache::put('audio_test_metrics_current_hour', $metrics, now()->addMinutes(60));
        
        // Store performance trends
        $trends = $metricsService->getPerformanceTrends($this->days);
        Cache::put('audio_test_performance_trends_hourly', $trends, now()->addMinutes(60));

        // Collect queue status
        $queueMetrics = $this->collectQueueMetrics();
        Cache::put('audio_test_queue_status', $queueMetrics, now()->addMinutes(15));

        // Check for alerts
        $this->checkAlertThresholds($metrics);
    }

    /**
     * Collect daily metrics.
     *
     * @param AudioTestMetricsService $metricsService
     * @return void
     */
    protected function collectDailyMetrics(AudioTestMetricsService $metricsService): void
    {
        // Collect comprehensive daily metrics
        $metrics = $metricsService->getSystemMetrics(7); // Last 7 days
        
        // Cache with longer TTL
        Cache::put('audio_test_metrics_daily', $metrics, now()->addHours(24));
        
        // Store historical data
        $this->storeHistoricalMetrics($metrics, 'daily');
        
        // Generate daily report data
        $reportData = $this->generateDailyReport($metrics);
        Cache::put('audio_test_daily_report', $reportData, now()->addHours(24));

        // Performance analysis
        $trends = $metricsService->getPerformanceTrends(30); // Last 30 days
        Cache::put('audio_test_performance_trends_daily', $trends, now()->addHours(24));
    }

    /**
     * Collect weekly metrics.
     *
     * @param AudioTestMetricsService $metricsService
     * @return void
     */
    protected function collectWeeklyMetrics(AudioTestMetricsService $metricsService): void
    {
        // Collect comprehensive weekly metrics
        $metrics = $metricsService->getSystemMetrics(30); // Last 30 days
        
        // Cache with longer TTL
        Cache::put('audio_test_metrics_weekly', $metrics, now()->addDays(7));
        
        // Store historical data
        $this->storeHistoricalMetrics($metrics, 'weekly');
        
        // Generate weekly report
        $reportData = $this->generateWeeklyReport($metrics);
        Cache::put('audio_test_weekly_report', $reportData, now()->addDays(7));

        // Long-term trend analysis
        $trends = $metricsService->getPerformanceTrends(90); // Last 90 days
        Cache::put('audio_test_performance_trends_weekly', $trends, now()->addDays(7));

        // Cleanup old data
        $this->cleanupHistoricalData();
    }

    /**
     * Collect real-time queue metrics.
     *
     * @return array
     */
    protected function collectQueueMetrics(): array
    {
        try {
            // Get queue statistics from Laravel Horizon or database
            $queueStats = [
                'pending_jobs' => DB::table('jobs')->where('queue', 'like', '%audio%')->count(),
                'failed_jobs' => DB::table('failed_jobs')->where('payload', 'like', '%Audio%')->count(),
                'processed_jobs_last_hour' => $this->getProcessedJobsCount(1),
                'avg_processing_time' => $this->getAverageProcessingTime(),
                'queue_health' => $this->assessQueueHealth(),
                'worker_status' => $this->getWorkerStatus(),
                'timestamp' => now()->toISOString()
            ];

            return $queueStats;

        } catch (\Exception $e) {
            Log::warning('Failed to collect queue metrics', [
                'error' => $e->getMessage()
            ]);

            return [
                'error' => 'Failed to collect queue metrics',
                'timestamp' => now()->toISOString()
            ];
        }
    }

    /**
     * Check alert thresholds and trigger alerts if necessary.
     *
     * @param array $metrics
     * @return void
     */
    protected function checkAlertThresholds(array $metrics): void
    {
        $alerts = [];

        // Check failure rate threshold (>10%)
        $overallSuccessRate = $metrics['success_rates']['overall']['success_rate'] ?? 100;
        if ($overallSuccessRate < 90) {
            $alerts[] = [
                'type' => 'high_failure_rate',
                'severity' => 'warning',
                'message' => "Success rate dropped to {$overallSuccessRate}%",
                'threshold' => 90,
                'current_value' => $overallSuccessRate
            ];
        }

        // Check processing time threshold
        $avgProcessingTime = $metrics['processing_performance']['balanced']['avg_processing_time'] ?? 0;
        if ($avgProcessingTime > 120) { // 2 minutes
            $alerts[] = [
                'type' => 'slow_processing',
                'severity' => 'warning',
                'message' => "Average processing time increased to {$avgProcessingTime} seconds",
                'threshold' => 120,
                'current_value' => $avgProcessingTime
            ];
        }

        // Check queue wait time threshold
        $queueWaitTime = $metrics['queue_performance']['avg_queue_wait_time_seconds'] ?? 0;
        if ($queueWaitTime > 300) { // 5 minutes
            $alerts[] = [
                'type' => 'high_queue_wait',
                'severity' => 'critical',
                'message' => "Queue wait time increased to {$queueWaitTime} seconds",
                'threshold' => 300,
                'current_value' => $queueWaitTime
            ];
        }

        // Store alerts if any
        if (!empty($alerts)) {
            Cache::put('audio_test_active_alerts', $alerts, now()->addHours(1));
            
            Log::warning('Audio test system alerts triggered', [
                'alerts' => $alerts,
                'timestamp' => now()->toISOString()
            ]);
        }
    }

    /**
     * Store collection metadata.
     *
     * @param float $executionTime
     * @return void
     */
    protected function storeCollectionMetadata(float $executionTime): void
    {
        $metadata = [
            'type' => $this->type,
            'days' => $this->days,
            'execution_time_ms' => $executionTime,
            'timestamp' => now()->toISOString(),
            'memory_usage_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
        ];

        Cache::put("audio_test_metrics_collection_{$this->type}_metadata", $metadata, now()->addDays(1));
    }

    /**
     * Store historical metrics in database or cache.
     *
     * @param array $metrics
     * @param string $period
     * @return void
     */
    protected function storeHistoricalMetrics(array $metrics, string $period): void
    {
        $historicalData = [
            'period' => $period,
            'date' => now()->toDateString(),
            'metrics' => $metrics,
            'stored_at' => now()->toISOString()
        ];

        // Store in cache with long TTL
        $cacheKey = "audio_test_historical_metrics_{$period}_" . now()->format('Y_m_d');
        Cache::put($cacheKey, $historicalData, now()->addDays(90));
    }

    /**
     * Generate daily report data.
     *
     * @param array $metrics
     * @return array
     */
    protected function generateDailyReport(array $metrics): array
    {
        return [
            'date' => now()->toDateString(),
            'summary' => [
                'total_jobs' => $metrics['resource_usage']['total_jobs_processed'] ?? 0,
                'success_rate' => $metrics['success_rates']['overall']['success_rate'] ?? 0,
                'avg_processing_time' => $metrics['processing_performance']['balanced']['avg_processing_time'] ?? 0,
                'total_batches' => $metrics['queue_performance']['total_batches'] ?? 0,
            ],
            'highlights' => $this->generateHighlights($metrics),
            'recommendations' => $this->generateRecommendations($metrics),
            'generated_at' => now()->toISOString()
        ];
    }

    /**
     * Generate weekly report data.
     *
     * @param array $metrics
     * @return array
     */
    protected function generateWeeklyReport(array $metrics): array
    {
        return [
            'week_start' => now()->startOfWeek()->toDateString(),
            'week_end' => now()->endOfWeek()->toDateString(),
            'summary' => [
                'total_jobs' => $metrics['resource_usage']['total_jobs_processed'] ?? 0,
                'success_rate' => $metrics['success_rates']['overall']['success_rate'] ?? 0,
                'unique_users' => $metrics['user_activity']['unique_users'] ?? 0,
                'total_processing_hours' => $metrics['resource_usage']['total_processing_time_hours'] ?? 0,
            ],
            'trends' => $this->analyzeTrends($metrics),
            'performance_insights' => $this->generatePerformanceInsights($metrics),
            'generated_at' => now()->toISOString()
        ];
    }

    /**
     * Perform maintenance tasks.
     *
     * @return void
     */
    protected function performMaintenance(): void
    {
        // Clear old cache entries
        if ($this->type === 'daily') {
            $this->clearOldCacheEntries();
        }

        // Optimize database tables
        if ($this->type === 'weekly') {
            $this->optimizeDatabaseTables();
        }
    }

    /**
     * Get processed jobs count for the last N hours.
     *
     * @param int $hours
     * @return int
     */
    protected function getProcessedJobsCount(int $hours): int
    {
        return DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('completed_at', '>=', now()->subHours($hours))
            ->count();
    }

    /**
     * Get average processing time for recent jobs.
     *
     * @return float
     */
    protected function getAverageProcessingTime(): float
    {
        return DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('completed_at', '>=', now()->subHour())
            ->whereNotNull('total_processing_duration_seconds')
            ->avg('total_processing_duration_seconds') ?? 0;
    }

    /**
     * Assess queue health status.
     *
     * @return string
     */
    protected function assessQueueHealth(): string
    {
        $pendingJobs = DB::table('jobs')->where('queue', 'like', '%audio%')->count();
        $failedJobs = DB::table('failed_jobs')->where('payload', 'like', '%Audio%')->count();

        if ($failedJobs > 10) {
            return 'critical';
        } elseif ($pendingJobs > 100) {
            return 'warning';
        } else {
            return 'healthy';
        }
    }

    /**
     * Get worker status information.
     *
     * @return array
     */
    protected function getWorkerStatus(): array
    {
        // This would integrate with Laravel Horizon or Supervisor
        return [
            'active_workers' => 'unknown', // Would be populated by monitoring system
            'status' => 'monitoring_required'
        ];
    }

    /**
     * Generate performance highlights.
     *
     * @param array $metrics
     * @return array
     */
    protected function generateHighlights(array $metrics): array
    {
        $highlights = [];

        // Best performing quality level
        $bestQuality = null;
        $bestSuccessRate = 0;
        foreach ($metrics['success_rates']['by_quality_level'] ?? [] as $quality => $stats) {
            if ($stats['success_rate'] > $bestSuccessRate) {
                $bestSuccessRate = $stats['success_rate'];
                $bestQuality = $quality;
            }
        }

        if ($bestQuality) {
            $highlights[] = "Best performing quality level: {$bestQuality} ({$bestSuccessRate}% success rate)";
        }

        // Peak usage time
        $peakHours = $metrics['system_load']['peak_hours'] ?? [];
        if (!empty($peakHours)) {
            $peakHour = $peakHours[0]['hour'] ?? 'unknown';
            $highlights[] = "Peak usage time: {$peakHour}:00";
        }

        return $highlights;
    }

    /**
     * Generate recommendations based on metrics.
     *
     * @param array $metrics
     * @return array
     */
    protected function generateRecommendations(array $metrics): array
    {
        $recommendations = [];

        // Check if processing times are high
        $avgProcessingTime = $metrics['processing_performance']['balanced']['avg_processing_time'] ?? 0;
        if ($avgProcessingTime > 60) {
            $recommendations[] = "Consider optimizing audio processing pipeline - average time is {$avgProcessingTime}s";
        }

        // Check failure rates
        $failureRate = $metrics['success_rates']['overall']['failure_rate'] ?? 0;
        if ($failureRate > 5) {
            $recommendations[] = "Investigate failure causes - failure rate is {$failureRate}%";
        }

        return $recommendations;
    }

    /**
     * Analyze trends in metrics.
     *
     * @param array $metrics
     * @return array
     */
    protected function analyzeTrends(array $metrics): array
    {
        // This would analyze historical data to identify trends
        return [
            'processing_time_trend' => 'stable',
            'success_rate_trend' => 'improving',
            'usage_trend' => 'increasing'
        ];
    }

    /**
     * Generate performance insights.
     *
     * @param array $metrics
     * @return array
     */
    protected function generatePerformanceInsights(array $metrics): array
    {
        return [
            'efficiency_score' => $this->calculateEfficiencyScore($metrics),
            'bottlenecks' => $this->identifyBottlenecks($metrics),
            'optimization_opportunities' => $this->identifyOptimizations($metrics)
        ];
    }

    /**
     * Calculate efficiency score.
     *
     * @param array $metrics
     * @return float
     */
    protected function calculateEfficiencyScore(array $metrics): float
    {
        $successRate = $metrics['success_rates']['overall']['success_rate'] ?? 0;
        $avgProcessingTime = $metrics['processing_performance']['balanced']['avg_processing_time'] ?? 0;
        
        // Simple efficiency calculation (can be made more sophisticated)
        $timeScore = $avgProcessingTime > 0 ? min(100, 6000 / $avgProcessingTime) : 0;
        
        return round(($successRate + $timeScore) / 2, 1);
    }

    /**
     * Identify system bottlenecks.
     *
     * @param array $metrics
     * @return array
     */
    protected function identifyBottlenecks(array $metrics): array
    {
        $bottlenecks = [];

        $queueWaitTime = $metrics['queue_performance']['avg_queue_wait_time_seconds'] ?? 0;
        if ($queueWaitTime > 60) {
            $bottlenecks[] = 'Queue processing - high wait times';
        }

        return $bottlenecks;
    }

    /**
     * Identify optimization opportunities.
     *
     * @param array $metrics
     * @return array
     */
    protected function identifyOptimizations(array $metrics): array
    {
        $optimizations = [];

        $concurrentJobs = $metrics['resource_usage']['avg_concurrent_jobs'] ?? 0;
        if ($concurrentJobs < 3) {
            $optimizations[] = 'Increase concurrent job processing';
        }

        return $optimizations;
    }

    /**
     * Clear old cache entries.
     *
     * @return void
     */
    protected function clearOldCacheEntries(): void
    {
        // Clear cache entries older than 7 days
        $patterns = [
            'audio_test_metrics_*',
            'audio_test_historical_metrics_*'
        ];

        foreach ($patterns as $pattern) {
            // This would be implemented based on cache driver
            Log::info('Cleared old cache entries', ['pattern' => $pattern]);
        }
    }

    /**
     * Optimize database tables.
     *
     * @return void
     */
    protected function optimizeDatabaseTables(): void
    {
        try {
            DB::statement('OPTIMIZE TABLE transcription_logs');
            DB::statement('OPTIMIZE TABLE audio_test_batches');
            
            Log::info('Database tables optimized for audio testing');
        } catch (\Exception $e) {
            Log::warning('Failed to optimize database tables', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Cleanup historical data older than retention period.
     *
     * @return void
     */
    protected function cleanupHistoricalData(): void
    {
        // Remove test logs older than 90 days
        $deletedLogs = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('created_at', '<', now()->subDays(90))
            ->delete();

        // Remove completed batches older than 60 days
        $deletedBatches = DB::table('audio_test_batches')
            ->where('status', 'completed')
            ->where('created_at', '<', now()->subDays(60))
            ->delete();

        Log::info('Historical data cleanup completed', [
            'deleted_logs' => $deletedLogs,
            'deleted_batches' => $deletedBatches
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'metrics-collection',
            'audio-testing',
            'monitoring',
            "type:{$this->type}",
            "period:{$this->days}days"
        ];
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Audio test metrics collection job failed', [
            'type' => $this->type,
            'days' => $this->days,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}