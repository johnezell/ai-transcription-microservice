<?php

namespace App\Services;

use App\Models\AudioTestBatch;
use App\Models\TranscriptionLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AudioTestMetricsService
{
    /**
     * Cache duration for metrics (in minutes).
     */
    private const CACHE_DURATION = 15;

    /**
     * Get comprehensive system performance metrics.
     *
     * @param int $days Number of days to analyze
     * @return array
     */
    public function getSystemMetrics(int $days = 30): array
    {
        $cacheKey = "audio_test_metrics_system_{$days}";
        
        return Cache::remember($cacheKey, self::CACHE_DURATION * 60, function () use ($days) {
            $startDate = Carbon::now()->subDays($days);
            
            return [
                'processing_performance' => $this->getProcessingPerformance($startDate),
                'resource_usage' => $this->getResourceUsage($startDate),
                'queue_performance' => $this->getQueuePerformance($startDate),
                'success_rates' => $this->getSuccessRates($startDate),
                'user_activity' => $this->getUserActivity($startDate),
                'system_load' => $this->getSystemLoad($startDate),
                'period' => [
                    'start_date' => $startDate->toISOString(),
                    'end_date' => Carbon::now()->toISOString(),
                    'days' => $days
                ]
            ];
        });
    }

    /**
     * Get processing performance metrics by quality level.
     *
     * @param Carbon $startDate
     * @return array
     */
    public function getProcessingPerformance(Carbon $startDate): array
    {
        $metrics = TranscriptionLog::testExtractions()
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('total_processing_duration_seconds')
            ->selectRaw('
                test_quality_level,
                COUNT(*) as total_processed,
                AVG(total_processing_duration_seconds) as avg_processing_time,
                MIN(total_processing_duration_seconds) as min_processing_time,
                MAX(total_processing_duration_seconds) as max_processing_time,
                STDDEV(total_processing_duration_seconds) as stddev_processing_time,
                AVG(audio_file_size) as avg_file_size,
                AVG(audio_duration_seconds) as avg_audio_duration
            ')
            ->groupBy('test_quality_level')
            ->get()
            ->keyBy('test_quality_level');

        $qualityLevels = ['fast', 'balanced', 'high', 'premium'];
        $performance = [];

        foreach ($qualityLevels as $level) {
            $data = $metrics->get($level);
            $performance[$level] = [
                'total_processed' => $data->total_processed ?? 0,
                'avg_processing_time' => round($data->avg_processing_time ?? 0, 2),
                'min_processing_time' => round($data->min_processing_time ?? 0, 2),
                'max_processing_time' => round($data->max_processing_time ?? 0, 2),
                'stddev_processing_time' => round($data->stddev_processing_time ?? 0, 2),
                'avg_file_size' => round($data->avg_file_size ?? 0),
                'avg_audio_duration' => round($data->avg_audio_duration ?? 0, 2),
                'efficiency_ratio' => $data && $data->avg_audio_duration > 0 
                    ? round($data->avg_processing_time / $data->avg_audio_duration, 2) 
                    : 0
            ];
        }

        return $performance;
    }

    /**
     * Get resource usage metrics.
     *
     * @param Carbon $startDate
     * @return array
     */
    public function getResourceUsage(Carbon $startDate): array
    {
        $totalJobs = TranscriptionLog::testExtractions()
            ->where('created_at', '>=', $startDate)
            ->count();

        $totalProcessingTime = TranscriptionLog::testExtractions()
            ->where('created_at', '>=', $startDate)
            ->sum('total_processing_duration_seconds');

        $totalAudioSize = TranscriptionLog::testExtractions()
            ->where('created_at', '>=', $startDate)
            ->sum('audio_file_size');

        $concurrentJobsStats = AudioTestBatch::where('created_at', '>=', $startDate)
            ->selectRaw('
                AVG(concurrent_jobs) as avg_concurrent_jobs,
                MAX(concurrent_jobs) as max_concurrent_jobs,
                MIN(concurrent_jobs) as min_concurrent_jobs
            ')
            ->first();

        return [
            'total_jobs_processed' => $totalJobs,
            'total_processing_time_hours' => round($totalProcessingTime / 3600, 2),
            'total_audio_size_mb' => round($totalAudioSize / (1024 * 1024), 2),
            'avg_concurrent_jobs' => round($concurrentJobsStats->avg_concurrent_jobs ?? 0, 1),
            'max_concurrent_jobs' => $concurrentJobsStats->max_concurrent_jobs ?? 0,
            'min_concurrent_jobs' => $concurrentJobsStats->min_concurrent_jobs ?? 0,
            'estimated_cpu_hours' => round($totalProcessingTime / 3600 * 0.8, 2), // Estimate 80% CPU usage
            'estimated_memory_usage_gb' => round($totalJobs * 0.5, 2), // Estimate 500MB per job
        ];
    }

    /**
     * Get queue performance metrics.
     *
     * @param Carbon $startDate
     * @return array
     */
    public function getQueuePerformance(Carbon $startDate): array
    {
        $batchStats = AudioTestBatch::where('created_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as total_batches,
                AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_batch_duration,
                AVG(estimated_duration) as avg_estimated_duration,
                AVG(total_segments) as avg_batch_size,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_batches,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_batches,
                SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as cancelled_batches
            ')
            ->first();

        $queueWaitTimes = TranscriptionLog::testExtractions()
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('started_at')
            ->whereNotNull('audio_extraction_started_at')
            ->selectRaw('
                AVG(TIMESTAMPDIFF(SECOND, started_at, audio_extraction_started_at)) as avg_queue_wait_time,
                MAX(TIMESTAMPDIFF(SECOND, started_at, audio_extraction_started_at)) as max_queue_wait_time,
                MIN(TIMESTAMPDIFF(SECOND, started_at, audio_extraction_started_at)) as min_queue_wait_time
            ')
            ->first();

        return [
            'total_batches' => $batchStats->total_batches ?? 0,
            'completed_batches' => $batchStats->completed_batches ?? 0,
            'failed_batches' => $batchStats->failed_batches ?? 0,
            'cancelled_batches' => $batchStats->cancelled_batches ?? 0,
            'batch_success_rate' => $batchStats->total_batches > 0 
                ? round(($batchStats->completed_batches / $batchStats->total_batches) * 100, 2) 
                : 0,
            'avg_batch_duration_minutes' => round(($batchStats->avg_batch_duration ?? 0) / 60, 2),
            'avg_estimated_duration_minutes' => round(($batchStats->avg_estimated_duration ?? 0) / 60, 2),
            'avg_batch_size' => round($batchStats->avg_batch_size ?? 0, 1),
            'avg_queue_wait_time_seconds' => round($queueWaitTimes->avg_queue_wait_time ?? 0, 2),
            'max_queue_wait_time_seconds' => round($queueWaitTimes->max_queue_wait_time ?? 0, 2),
            'min_queue_wait_time_seconds' => round($queueWaitTimes->min_queue_wait_time ?? 0, 2),
            'throughput_jobs_per_hour' => $this->calculateThroughput($startDate),
        ];
    }

    /**
     * Get success rates by quality level and batch size.
     *
     * @param Carbon $startDate
     * @return array
     */
    public function getSuccessRates(Carbon $startDate): array
    {
        $qualityLevelStats = TranscriptionLog::testExtractions()
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                test_quality_level,
                COUNT(*) as total,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed
            ')
            ->groupBy('test_quality_level')
            ->get()
            ->keyBy('test_quality_level');

        $batchSizeStats = AudioTestBatch::where('created_at', '>=', $startDate)
            ->selectRaw('
                CASE 
                    WHEN total_segments <= 10 THEN "small"
                    WHEN total_segments <= 50 THEN "medium"
                    ELSE "large"
                END as batch_size_category,
                COUNT(*) as total,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed
            ')
            ->groupBy('batch_size_category')
            ->get()
            ->keyBy('batch_size_category');

        return [
            'by_quality_level' => $this->formatSuccessRateStats($qualityLevelStats),
            'by_batch_size' => $this->formatSuccessRateStats($batchSizeStats),
            'overall' => $this->getOverallSuccessRate($startDate),
        ];
    }

    /**
     * Get user activity metrics.
     *
     * @param Carbon $startDate
     * @return array
     */
    public function getUserActivity(Carbon $startDate): array
    {
        $userStats = AudioTestBatch::where('created_at', '>=', $startDate)
            ->selectRaw('
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(*) as total_batches,
                AVG(total_segments) as avg_segments_per_batch
            ')
            ->first();

        $topUsers = AudioTestBatch::where('created_at', '>=', $startDate)
            ->selectRaw('
                user_id,
                COUNT(*) as batch_count,
                SUM(total_segments) as total_segments,
                AVG(total_segments) as avg_segments_per_batch
            ')
            ->groupBy('user_id')
            ->orderBy('batch_count', 'desc')
            ->limit(10)
            ->get();

        $dailyActivity = AudioTestBatch::where('created_at', '>=', $startDate)
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as batches_created,
                COUNT(DISTINCT user_id) as unique_users,
                SUM(total_segments) as segments_processed
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'unique_users' => $userStats->unique_users ?? 0,
            'total_batches' => $userStats->total_batches ?? 0,
            'avg_segments_per_batch' => round($userStats->avg_segments_per_batch ?? 0, 1),
            'top_users' => $topUsers->toArray(),
            'daily_activity' => $dailyActivity->toArray(),
            'usage_patterns' => $this->analyzeUsagePatterns($startDate),
        ];
    }

    /**
     * Get system load metrics.
     *
     * @param Carbon $startDate
     * @return array
     */
    public function getSystemLoad(Carbon $startDate): array
    {
        $hourlyLoad = TranscriptionLog::testExtractions()
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                HOUR(created_at) as hour,
                COUNT(*) as job_count,
                AVG(total_processing_duration_seconds) as avg_processing_time
            ')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $peakHours = $hourlyLoad->sortByDesc('job_count')->take(3);
        $lowHours = $hourlyLoad->sortBy('job_count')->take(3);

        return [
            'hourly_distribution' => $hourlyLoad->toArray(),
            'peak_hours' => $peakHours->values()->toArray(),
            'low_activity_hours' => $lowHours->values()->toArray(),
            'load_variance' => $this->calculateLoadVariance($hourlyLoad),
            'recommended_maintenance_window' => $this->getMaintenanceWindow($hourlyLoad),
        ];
    }

    /**
     * Calculate job throughput per hour.
     *
     * @param Carbon $startDate
     * @return float
     */
    private function calculateThroughput(Carbon $startDate): float
    {
        $totalJobs = TranscriptionLog::testExtractions()
            ->where('created_at', '>=', $startDate)
            ->count();

        $hours = Carbon::now()->diffInHours($startDate);
        
        return $hours > 0 ? round($totalJobs / $hours, 2) : 0;
    }

    /**
     * Format success rate statistics.
     *
     * @param \Illuminate\Support\Collection $stats
     * @return array
     */
    private function formatSuccessRateStats($stats): array
    {
        $formatted = [];
        
        foreach ($stats as $key => $stat) {
            $total = $stat->total ?? 0;
            $completed = $stat->completed ?? 0;
            $failed = $stat->failed ?? 0;
            
            $formatted[$key] = [
                'total' => $total,
                'completed' => $completed,
                'failed' => $failed,
                'success_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
                'failure_rate' => $total > 0 ? round(($failed / $total) * 100, 2) : 0,
            ];
        }
        
        return $formatted;
    }

    /**
     * Get overall success rate.
     *
     * @param Carbon $startDate
     * @return array
     */
    private function getOverallSuccessRate(Carbon $startDate): array
    {
        $stats = TranscriptionLog::testExtractions()
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = "processing" THEN 1 ELSE 0 END) as processing
            ')
            ->first();

        $total = $stats->total ?? 0;
        
        return [
            'total' => $total,
            'completed' => $stats->completed ?? 0,
            'failed' => $stats->failed ?? 0,
            'processing' => $stats->processing ?? 0,
            'success_rate' => $total > 0 ? round(($stats->completed / $total) * 100, 2) : 0,
            'failure_rate' => $total > 0 ? round(($stats->failed / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Analyze usage patterns.
     *
     * @param Carbon $startDate
     * @return array
     */
    private function analyzeUsagePatterns(Carbon $startDate): array
    {
        $patterns = [];
        
        // Quality level preferences
        $qualityPreferences = TranscriptionLog::testExtractions()
            ->where('created_at', '>=', $startDate)
            ->selectRaw('test_quality_level, COUNT(*) as count')
            ->groupBy('test_quality_level')
            ->orderBy('count', 'desc')
            ->get();

        $patterns['quality_preferences'] = $qualityPreferences->toArray();

        // Batch size preferences
        $batchSizes = AudioTestBatch::where('created_at', '>=', $startDate)
            ->selectRaw('
                CASE 
                    WHEN total_segments <= 5 THEN "1-5"
                    WHEN total_segments <= 10 THEN "6-10"
                    WHEN total_segments <= 25 THEN "11-25"
                    WHEN total_segments <= 50 THEN "26-50"
                    ELSE "50+"
                END as size_range,
                COUNT(*) as count
            ')
            ->groupBy('size_range')
            ->get();

        $patterns['batch_size_preferences'] = $batchSizes->toArray();

        return $patterns;
    }

    /**
     * Calculate load variance across hours.
     *
     * @param \Illuminate\Support\Collection $hourlyLoad
     * @return float
     */
    private function calculateLoadVariance($hourlyLoad): float
    {
        if ($hourlyLoad->isEmpty()) {
            return 0;
        }

        $jobCounts = $hourlyLoad->pluck('job_count');
        $mean = $jobCounts->avg();
        $variance = $jobCounts->map(function ($count) use ($mean) {
            return pow($count - $mean, 2);
        })->avg();

        return round(sqrt($variance), 2);
    }

    /**
     * Get recommended maintenance window based on low activity hours.
     *
     * @param \Illuminate\Support\Collection $hourlyLoad
     * @return array
     */
    private function getMaintenanceWindow($hourlyLoad): array
    {
        if ($hourlyLoad->isEmpty()) {
            return ['start_hour' => 2, 'end_hour' => 4, 'reason' => 'Default low activity period'];
        }

        $lowActivityHours = $hourlyLoad->sortBy('job_count')->take(3);
        $startHour = $lowActivityHours->min('hour');
        $endHour = $lowActivityHours->max('hour');

        return [
            'start_hour' => $startHour,
            'end_hour' => $endHour,
            'reason' => 'Based on historical low activity periods',
            'avg_jobs_during_window' => round($lowActivityHours->avg('job_count'), 1),
        ];
    }

    /**
     * Clear metrics cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $patterns = [
            'audio_test_metrics_system_*',
            'audio_test_metrics_processing_*',
            'audio_test_metrics_queue_*',
        ];

        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
        }

        Log::info('Audio test metrics cache cleared');
    }

    /**
     * Get performance trends over time.
     *
     * @param int $days
     * @return array
     */
    public function getPerformanceTrends(int $days = 30): array
    {
        $cacheKey = "audio_test_metrics_trends_{$days}";
        
        return Cache::remember($cacheKey, self::CACHE_DURATION * 60, function () use ($days) {
            $startDate = Carbon::now()->subDays($days);
            
            $dailyMetrics = TranscriptionLog::testExtractions()
                ->where('created_at', '>=', $startDate)
                ->selectRaw('
                    DATE(created_at) as date,
                    COUNT(*) as total_jobs,
                    AVG(total_processing_duration_seconds) as avg_processing_time,
                    SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_jobs,
                    SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_jobs,
                    AVG(audio_file_size) as avg_file_size
                ')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            return [
                'daily_metrics' => $dailyMetrics->toArray(),
                'trend_analysis' => $this->analyzeTrends($dailyMetrics),
            ];
        });
    }

    /**
     * Analyze performance trends.
     *
     * @param \Illuminate\Support\Collection $dailyMetrics
     * @return array
     */
    private function analyzeTrends($dailyMetrics): array
    {
        if ($dailyMetrics->count() < 2) {
            return ['insufficient_data' => true];
        }

        $first = $dailyMetrics->first();
        $last = $dailyMetrics->last();

        $processingTimeTrend = $last->avg_processing_time - $first->avg_processing_time;
        $jobVolumeTrend = $last->total_jobs - $first->total_jobs;
        $successRateTrend = ($last->total_jobs > 0 ? $last->completed_jobs / $last->total_jobs : 0) - 
                           ($first->total_jobs > 0 ? $first->completed_jobs / $first->total_jobs : 0);

        return [
            'processing_time_trend' => [
                'change_seconds' => round($processingTimeTrend, 2),
                'direction' => $processingTimeTrend > 0 ? 'increasing' : 'decreasing',
                'percentage_change' => $first->avg_processing_time > 0 
                    ? round(($processingTimeTrend / $first->avg_processing_time) * 100, 2) 
                    : 0
            ],
            'job_volume_trend' => [
                'change' => $jobVolumeTrend,
                'direction' => $jobVolumeTrend > 0 ? 'increasing' : 'decreasing',
                'percentage_change' => $first->total_jobs > 0 
                    ? round(($jobVolumeTrend / $first->total_jobs) * 100, 2) 
                    : 0
            ],
            'success_rate_trend' => [
                'change' => round($successRateTrend * 100, 2),
                'direction' => $successRateTrend > 0 ? 'improving' : 'declining'
            ]
        ];
    }
}