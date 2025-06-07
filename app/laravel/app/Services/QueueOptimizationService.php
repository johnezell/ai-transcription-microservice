<?php

namespace App\Services;

use App\Models\AudioTestBatch;
use App\Models\TranscriptionLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Carbon\Carbon;

class QueueOptimizationService
{
    /**
     * Default queue configuration.
     */
    private const DEFAULT_CONFIG = [
        'max_concurrent_jobs' => 10,
        'priority_levels' => ['high', 'normal', 'low'],
        'retry_attempts' => 3,
        'timeout_seconds' => 300,
        'memory_limit_mb' => 512,
    ];

    /**
     * Queue performance thresholds.
     */
    private const PERFORMANCE_THRESHOLDS = [
        'high_load_pending_jobs' => 50,
        'critical_load_pending_jobs' => 100,
        'high_wait_time_seconds' => 300,
        'critical_wait_time_seconds' => 600,
        'high_failure_rate_percent' => 10,
        'critical_failure_rate_percent' => 20,
    ];

    /**
     * Optimize queue configuration based on current load and performance.
     *
     * @return array
     */
    public function optimizeQueueConfiguration(): array
    {
        try {
            Log::info('Starting queue optimization analysis');

            $currentMetrics = $this->getCurrentQueueMetrics();
            $historicalData = $this->getHistoricalPerformanceData();
            $loadAnalysis = $this->analyzeCurrentLoad($currentMetrics);
            
            $recommendations = $this->generateOptimizationRecommendations(
                $currentMetrics,
                $historicalData,
                $loadAnalysis
            );

            $optimizedConfig = $this->calculateOptimalConfiguration($recommendations);

            // Cache the optimization results
            Cache::put('queue_optimization_results', [
                'current_metrics' => $currentMetrics,
                'load_analysis' => $loadAnalysis,
                'recommendations' => $recommendations,
                'optimized_config' => $optimizedConfig,
                'generated_at' => now()->toISOString()
            ], now()->addMinutes(30));

            Log::info('Queue optimization completed', [
                'recommendations_count' => count($recommendations),
                'load_status' => $loadAnalysis['status']
            ]);

            return [
                'success' => true,
                'current_metrics' => $currentMetrics,
                'load_analysis' => $loadAnalysis,
                'recommendations' => $recommendations,
                'optimized_config' => $optimizedConfig,
            ];

        } catch (\Exception $e) {
            Log::error('Queue optimization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'fallback_config' => self::DEFAULT_CONFIG
            ];
        }
    }

    /**
     * Implement dynamic queue scaling based on load.
     *
     * @param array $loadMetrics
     * @return array
     */
    public function implementDynamicScaling(array $loadMetrics = null): array
    {
        if (!$loadMetrics) {
            $loadMetrics = $this->getCurrentQueueMetrics();
        }

        $scalingActions = [];
        $currentConfig = $this->getCurrentQueueConfig();

        // Scale up if high load detected
        if ($loadMetrics['pending_jobs'] > self::PERFORMANCE_THRESHOLDS['high_load_pending_jobs']) {
            $newConcurrency = min(
                $currentConfig['max_concurrent_jobs'] * 1.5,
                20 // Maximum allowed concurrent jobs
            );

            if ($newConcurrency > $currentConfig['max_concurrent_jobs']) {
                $scalingActions[] = [
                    'action' => 'scale_up',
                    'type' => 'concurrent_jobs',
                    'from' => $currentConfig['max_concurrent_jobs'],
                    'to' => $newConcurrency,
                    'reason' => 'High pending job count detected'
                ];
            }
        }

        // Scale down if low load detected
        if ($loadMetrics['pending_jobs'] < 5 && $loadMetrics['processing_jobs'] < 2) {
            $newConcurrency = max(
                $currentConfig['max_concurrent_jobs'] * 0.7,
                3 // Minimum concurrent jobs
            );

            if ($newConcurrency < $currentConfig['max_concurrent_jobs']) {
                $scalingActions[] = [
                    'action' => 'scale_down',
                    'type' => 'concurrent_jobs',
                    'from' => $currentConfig['max_concurrent_jobs'],
                    'to' => $newConcurrency,
                    'reason' => 'Low load detected - conserving resources'
                ];
            }
        }

        // Adjust timeout based on processing patterns
        $avgProcessingTime = $this->getAverageProcessingTime();
        $recommendedTimeout = max(300, $avgProcessingTime * 2);

        if (abs($recommendedTimeout - $currentConfig['timeout_seconds']) > 60) {
            $scalingActions[] = [
                'action' => 'adjust_timeout',
                'type' => 'timeout_seconds',
                'from' => $currentConfig['timeout_seconds'],
                'to' => $recommendedTimeout,
                'reason' => 'Optimizing timeout based on processing patterns'
            ];
        }

        // Apply scaling actions if any
        if (!empty($scalingActions)) {
            $this->applyScalingActions($scalingActions);
        }

        return [
            'scaling_actions' => $scalingActions,
            'load_metrics' => $loadMetrics,
            'applied_at' => now()->toISOString()
        ];
    }

    /**
     * Manage priority queue assignments for different test types.
     *
     * @param string $jobType
     * @param array $jobContext
     * @return string
     */
    public function assignJobPriority(string $jobType, array $jobContext = []): string
    {
        // High priority conditions
        if ($this->isHighPriorityJob($jobType, $jobContext)) {
            return 'high';
        }

        // Low priority conditions
        if ($this->isLowPriorityJob($jobType, $jobContext)) {
            return 'low';
        }

        // Default to normal priority
        return 'normal';
    }

    /**
     * Optimize job scheduling based on resource availability.
     *
     * @param array $pendingJobs
     * @return array
     */
    public function optimizeJobScheduling(array $pendingJobs): array
    {
        $resourceMetrics = $this->getResourceMetrics();
        $schedulingPlan = [];

        // Sort jobs by priority and estimated resource usage
        usort($pendingJobs, function ($a, $b) {
            $priorityOrder = ['high' => 3, 'normal' => 2, 'low' => 1];
            $aPriority = $priorityOrder[$a['priority'] ?? 'normal'] ?? 2;
            $bPriority = $priorityOrder[$b['priority'] ?? 'normal'] ?? 2;

            if ($aPriority === $bPriority) {
                // Secondary sort by estimated processing time (shorter first)
                return ($a['estimated_duration'] ?? 0) <=> ($b['estimated_duration'] ?? 0);
            }

            return $bPriority <=> $aPriority;
        });

        $availableSlots = $this->calculateAvailableProcessingSlots($resourceMetrics);
        $scheduledCount = 0;

        foreach ($pendingJobs as $job) {
            if ($scheduledCount >= $availableSlots) {
                $schedulingPlan[] = [
                    'job_id' => $job['id'],
                    'action' => 'defer',
                    'reason' => 'Resource capacity reached',
                    'estimated_delay' => $this->estimateDelay($scheduledCount - $availableSlots)
                ];
                continue;
            }

            $resourceRequirement = $this->estimateJobResourceRequirement($job);
            
            if ($this->canScheduleJob($resourceRequirement, $resourceMetrics)) {
                $schedulingPlan[] = [
                    'job_id' => $job['id'],
                    'action' => 'schedule',
                    'priority' => $job['priority'] ?? 'normal',
                    'estimated_start' => now()->addSeconds($scheduledCount * 10)->toISOString()
                ];
                $scheduledCount++;
            } else {
                $schedulingPlan[] = [
                    'job_id' => $job['id'],
                    'action' => 'defer',
                    'reason' => 'Insufficient resources',
                    'required_resources' => $resourceRequirement
                ];
            }
        }

        return [
            'scheduling_plan' => $schedulingPlan,
            'resource_metrics' => $resourceMetrics,
            'available_slots' => $availableSlots,
            'scheduled_count' => $scheduledCount
        ];
    }

    /**
     * Implement load balancing across multiple workers.
     *
     * @return array
     */
    public function implementLoadBalancing(): array
    {
        $workers = $this->getWorkerStatus();
        $loadBalancingStrategy = $this->determineLoadBalancingStrategy($workers);
        
        $balancingActions = [];

        foreach ($workers as $workerId => $worker) {
            $load = $worker['current_load'] ?? 0;
            $capacity = $worker['capacity'] ?? 100;
            $utilization = $capacity > 0 ? ($load / $capacity) * 100 : 0;

            if ($utilization > 90) {
                $balancingActions[] = [
                    'worker_id' => $workerId,
                    'action' => 'reduce_load',
                    'current_utilization' => $utilization,
                    'recommended_action' => 'Redistribute jobs to other workers'
                ];
            } elseif ($utilization < 30 && count($workers) > 1) {
                $balancingActions[] = [
                    'worker_id' => $workerId,
                    'action' => 'increase_load',
                    'current_utilization' => $utilization,
                    'recommended_action' => 'Accept more jobs from overloaded workers'
                ];
            }
        }

        return [
            'strategy' => $loadBalancingStrategy,
            'workers' => $workers,
            'balancing_actions' => $balancingActions,
            'overall_utilization' => $this->calculateOverallUtilization($workers)
        ];
    }

    /**
     * Optimize retry logic for failed jobs.
     *
     * @param array $failedJobs
     * @return array
     */
    public function optimizeRetryLogic(array $failedJobs): array
    {
        $retryRecommendations = [];

        foreach ($failedJobs as $job) {
            $failureAnalysis = $this->analyzeJobFailure($job);
            $retryStrategy = $this->determineRetryStrategy($failureAnalysis);

            $retryRecommendations[] = [
                'job_id' => $job['id'],
                'failure_type' => $failureAnalysis['type'],
                'retry_strategy' => $retryStrategy,
                'recommended_delay' => $retryStrategy['delay_seconds'],
                'max_attempts' => $retryStrategy['max_attempts'],
                'success_probability' => $retryStrategy['success_probability']
            ];
        }

        return [
            'retry_recommendations' => $retryRecommendations,
            'total_failed_jobs' => count($failedJobs),
            'retry_eligible' => count(array_filter($retryRecommendations, fn($r) => $r['retry_strategy']['should_retry']))
        ];
    }

    /**
     * Get current queue metrics.
     *
     * @return array
     */
    protected function getCurrentQueueMetrics(): array
    {
        return [
            'pending_jobs' => DB::table('jobs')->where('queue', 'like', '%audio%')->count(),
            'processing_jobs' => DB::table('transcription_logs')
                ->where('is_test_extraction', true)
                ->where('status', 'processing')
                ->count(),
            'failed_jobs_24h' => DB::table('failed_jobs')
                ->where('payload', 'like', '%Audio%')
                ->where('failed_at', '>=', now()->subDay())
                ->count(),
            'completed_jobs_1h' => DB::table('transcription_logs')
                ->where('is_test_extraction', true)
                ->where('status', 'completed')
                ->where('completed_at', '>=', now()->subHour())
                ->count(),
            'avg_wait_time' => $this->calculateAverageWaitTime(),
            'avg_processing_time' => $this->getAverageProcessingTime(),
            'throughput_per_hour' => $this->calculateThroughput(),
        ];
    }

    /**
     * Get historical performance data.
     *
     * @return array
     */
    protected function getHistoricalPerformanceData(): array
    {
        $days = 7;
        $startDate = now()->subDays($days);

        return [
            'avg_daily_jobs' => DB::table('transcription_logs')
                ->where('is_test_extraction', true)
                ->where('created_at', '>=', $startDate)
                ->count() / $days,
            'peak_concurrent_jobs' => DB::table('audio_test_batches')
                ->where('created_at', '>=', $startDate)
                ->max('concurrent_jobs') ?? 1,
            'success_rate' => $this->calculateHistoricalSuccessRate($startDate),
            'performance_trends' => $this->analyzePerformanceTrends($startDate),
        ];
    }

    /**
     * Analyze current system load.
     *
     * @param array $metrics
     * @return array
     */
    protected function analyzeCurrentLoad(array $metrics): array
    {
        $status = 'normal';
        $issues = [];

        if ($metrics['pending_jobs'] > self::PERFORMANCE_THRESHOLDS['critical_load_pending_jobs']) {
            $status = 'critical';
            $issues[] = 'Critical pending job backlog';
        } elseif ($metrics['pending_jobs'] > self::PERFORMANCE_THRESHOLDS['high_load_pending_jobs']) {
            $status = 'high';
            $issues[] = 'High pending job count';
        }

        if ($metrics['avg_wait_time'] > self::PERFORMANCE_THRESHOLDS['critical_wait_time_seconds']) {
            $status = 'critical';
            $issues[] = 'Critical queue wait times';
        } elseif ($metrics['avg_wait_time'] > self::PERFORMANCE_THRESHOLDS['high_wait_time_seconds']) {
            if ($status !== 'critical') $status = 'high';
            $issues[] = 'High queue wait times';
        }

        $failureRate = $this->calculateRecentFailureRate();
        if ($failureRate > self::PERFORMANCE_THRESHOLDS['critical_failure_rate_percent']) {
            $status = 'critical';
            $issues[] = 'Critical failure rate';
        } elseif ($failureRate > self::PERFORMANCE_THRESHOLDS['high_failure_rate_percent']) {
            if ($status !== 'critical') $status = 'high';
            $issues[] = 'High failure rate';
        }

        return [
            'status' => $status,
            'issues' => $issues,
            'load_score' => $this->calculateLoadScore($metrics),
            'recommendations' => $this->generateLoadRecommendations($status, $issues)
        ];
    }

    /**
     * Generate optimization recommendations.
     *
     * @param array $currentMetrics
     * @param array $historicalData
     * @param array $loadAnalysis
     * @return array
     */
    protected function generateOptimizationRecommendations(array $currentMetrics, array $historicalData, array $loadAnalysis): array
    {
        $recommendations = [];

        // Concurrency recommendations
        if ($currentMetrics['pending_jobs'] > 20) {
            $recommendations[] = [
                'type' => 'concurrency',
                'action' => 'increase',
                'current_value' => $this->getCurrentConcurrency(),
                'recommended_value' => min(15, $this->getCurrentConcurrency() * 1.5),
                'reason' => 'High pending job count detected',
                'priority' => 'high'
            ];
        }

        // Timeout recommendations
        if ($currentMetrics['avg_processing_time'] > 180) {
            $recommendations[] = [
                'type' => 'timeout',
                'action' => 'increase',
                'current_value' => 300,
                'recommended_value' => $currentMetrics['avg_processing_time'] * 1.5,
                'reason' => 'Processing times exceed current timeout threshold',
                'priority' => 'medium'
            ];
        }

        // Memory recommendations
        if ($this->detectMemoryPressure()) {
            $recommendations[] = [
                'type' => 'memory',
                'action' => 'increase',
                'current_value' => 512,
                'recommended_value' => 768,
                'reason' => 'Memory pressure detected in job processing',
                'priority' => 'high'
            ];
        }

        // Queue priority recommendations
        if ($loadAnalysis['status'] === 'high' || $loadAnalysis['status'] === 'critical') {
            $recommendations[] = [
                'type' => 'priority_queue',
                'action' => 'implement',
                'reason' => 'High load detected - implement priority queuing',
                'priority' => 'high'
            ];
        }

        return $recommendations;
    }

    /**
     * Calculate optimal configuration based on recommendations.
     *
     * @param array $recommendations
     * @return array
     */
    protected function calculateOptimalConfiguration(array $recommendations): array
    {
        $config = self::DEFAULT_CONFIG;

        foreach ($recommendations as $rec) {
            switch ($rec['type']) {
                case 'concurrency':
                    $config['max_concurrent_jobs'] = $rec['recommended_value'];
                    break;
                case 'timeout':
                    $config['timeout_seconds'] = $rec['recommended_value'];
                    break;
                case 'memory':
                    $config['memory_limit_mb'] = $rec['recommended_value'];
                    break;
            }
        }

        return $config;
    }

    /**
     * Calculate average wait time for jobs.
     *
     * @return float
     */
    protected function calculateAverageWaitTime(): float
    {
        return DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('audio_extraction_started_at', '>=', now()->subHour())
            ->whereNotNull('started_at')
            ->whereNotNull('audio_extraction_started_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, started_at, audio_extraction_started_at)) as avg_wait')
            ->value('avg_wait') ?? 0;
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
            ->where('completed_at', '>=', now()->subHours(6))
            ->whereNotNull('total_processing_duration_seconds')
            ->avg('total_processing_duration_seconds') ?? 60;
    }

    /**
     * Calculate current throughput.
     *
     * @return float
     */
    protected function calculateThroughput(): float
    {
        $completedJobs = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('completed_at', '>=', now()->subHour())
            ->count();

        return $completedJobs; // Jobs per hour
    }

    /**
     * Calculate recent failure rate.
     *
     * @return float
     */
    protected function calculateRecentFailureRate(): float
    {
        $totalJobs = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('created_at', '>=', now()->subHours(6))
            ->count();

        $failedJobs = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subHours(6))
            ->count();

        return $totalJobs > 0 ? ($failedJobs / $totalJobs) * 100 : 0;
    }

    /**
     * Check if job should have high priority.
     *
     * @param string $jobType
     * @param array $jobContext
     * @return bool
     */
    protected function isHighPriorityJob(string $jobType, array $jobContext): bool
    {
        // Single test jobs get higher priority than batch jobs
        if ($jobType === 'single_test') {
            return true;
        }

        // Small batches (≤5 segments) get higher priority
        if (isset($jobContext['batch_size']) && $jobContext['batch_size'] <= 5) {
            return true;
        }

        // Premium quality tests get higher priority
        if (isset($jobContext['quality_level']) && $jobContext['quality_level'] === 'premium') {
            return true;
        }

        return false;
    }

    /**
     * Check if job should have low priority.
     *
     * @param string $jobType
     * @param array $jobContext
     * @return bool
     */
    protected function isLowPriorityJob(string $jobType, array $jobContext): bool
    {
        // Large batches (>50 segments) get lower priority
        if (isset($jobContext['batch_size']) && $jobContext['batch_size'] > 50) {
            return true;
        }

        // Fast quality tests can have lower priority during high load
        if (isset($jobContext['quality_level']) && $jobContext['quality_level'] === 'fast') {
            $currentLoad = $this->getCurrentQueueMetrics()['pending_jobs'];
            return $currentLoad > 30;
        }

        return false;
    }

    /**
     * Get current queue configuration.
     *
     * @return array
     */
    protected function getCurrentQueueConfig(): array
    {
        return Cache::get('current_queue_config', self::DEFAULT_CONFIG);
    }

    /**
     * Get current concurrency setting.
     *
     * @return int
     */
    protected function getCurrentConcurrency(): int
    {
        return config('queue.connections.database.max_concurrent_jobs', 5);
    }

    /**
     * Detect memory pressure in job processing.
     *
     * @return bool
     */
    protected function detectMemoryPressure(): bool
    {
        // This would integrate with system monitoring
        // For now, return false as placeholder
        return false;
    }

    /**
     * Calculate historical success rate.
     *
     * @param Carbon $startDate
     * @return float
     */
    protected function calculateHistoricalSuccessRate(Carbon $startDate): float
    {
        $totalJobs = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('created_at', '>=', $startDate)
            ->count();

        $successfulJobs = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('status', 'completed')
            ->where('created_at', '>=', $startDate)
            ->count();

        return $totalJobs > 0 ? ($successfulJobs / $totalJobs) * 100 : 0;
    }

    /**
     * Analyze performance trends.
     *
     * @param Carbon $startDate
     * @return array
     */
    protected function analyzePerformanceTrends(Carbon $startDate): array
    {
        // Placeholder for trend analysis
        return [
            'processing_time_trend' => 'stable',
            'throughput_trend' => 'increasing',
            'failure_rate_trend' => 'decreasing'
        ];
    }

    /**
     * Calculate load score.
     *
     * @param array $metrics
     * @return float
     */
    protected function calculateLoadScore(array $metrics): float
    {
        $pendingScore = min(100, ($metrics['pending_jobs'] / 50) * 100);
        $waitTimeScore = min(100, ($metrics['avg_wait_time'] / 300) * 100);
        $processingScore = min(100, ($metrics['avg_processing_time'] / 120) * 100);

        return round(($pendingScore + $waitTimeScore + $processingScore) / 3, 1);
    }

    /**
     * Generate load-based recommendations.
     *
     * @param string $status
     * @param array $issues
     * @return array
     */
    protected function generateLoadRecommendations(string $status, array $issues): array
    {
        $recommendations = [];

        if ($status === 'critical') {
            $recommendations[] = 'Immediately increase worker capacity';
            $recommendations[] = 'Consider implementing emergency load shedding';
        } elseif ($status === 'high') {
            $recommendations[] = 'Scale up processing capacity';
            $recommendations[] = 'Monitor queue closely for further degradation';
        }

        return $recommendations;
    }

    /**
     * Apply scaling actions to the queue configuration.
     *
     * @param array $scalingActions
     * @return void
     */
    protected function applyScalingActions(array $scalingActions): void
    {
        foreach ($scalingActions as $action) {
            Log::info('Applying queue scaling action', $action);
            
            // In a real implementation, this would update queue configuration
            // For now, we'll just cache the new configuration
            $currentConfig = $this->getCurrentQueueConfig();
            
            switch ($action['type']) {
                case 'concurrent_jobs':
                    $currentConfig['max_concurrent_jobs'] = $action['to'];
                    break;
                case 'timeout_seconds':
                    $currentConfig['timeout_seconds'] = $action['to'];
                    break;
            }
            
            Cache::put('current_queue_config', $currentConfig, now()->addHours(1));
        }
    }

    /**
     * Get resource metrics for scheduling optimization.
     *
     * @return array
     */
    protected function getResourceMetrics(): array
    {
        return [
            'cpu_usage' => 50, // Placeholder
            'memory_usage' => 60, // Placeholder
            'disk_io' => 30, // Placeholder
            'network_io' => 20, // Placeholder
        ];
    }

    /**
     * Calculate available processing slots.
     *
     * @param array $resourceMetrics
     * @return int
     */
    protected function calculateAvailableProcessingSlots(array $resourceMetrics): int
    {
        $maxSlots = $this->getCurrentConcurrency();
        $currentProcessing = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('status', 'processing')
            ->count();

        return max(0, $maxSlots - $currentProcessing);
    }

    /**
     * Estimate job resource requirement.
     *
     * @param array $job
     * @return array
     */
    protected function estimateJobResourceRequirement(array $job): array
    {
        $qualityLevel = $job['quality_level'] ?? 'balanced';
        
        $requirements = [
            'fast' => ['cpu' => 30, 'memory' => 256],
            'balanced' => ['cpu' => 50, 'memory' => 512],
            'high' => ['cpu' => 70, 'memory' => 768],
            'premium' => ['cpu' => 90, 'memory' => 1024],
        ];

        return $requirements[$qualityLevel] ?? $requirements['balanced'];
    }

    /**
     * Check if job can be scheduled with current resources.
     *
     * @param array $requirement
     * @param array $available
     * @return bool
     */
    protected function canScheduleJob(array $requirement, array $available): bool
    {
        return ($available['cpu_usage'] + $requirement['cpu']) <= 90 &&
               ($available['memory_usage'] + ($requirement['memory'] / 10)) <= 90;
    }

    /**
     * Estimate delay for deferred jobs.
     *
     * @param int $position
     * @return int
     */
    protected function estimateDelay(int $position): int
    {
        $avgProcessingTime = $this->getAverageProcessingTime();
        return $position * $avgProcessingTime;
    }

    /**
     * Get worker status information.
     *
     * @return array
     */
    protected function getWorkerStatus(): array
    {
        // Placeholder - would integrate with actual worker monitoring
        return [
            'worker_1' => ['current_load' => 80, 'capacity' => 100, 'status' => 'active'],
            'worker_2' => ['current_load' => 60, 'capacity' => 100, 'status' => 'active'],
        ];
    }

    /**
     * Determine load balancing strategy.
     *
     * @param array $workers
     * @return string
     */
    protected function determineLoadBalancingStrategy(array $workers): string
    {
        $totalUtilization = $this->calculateOverallUtilization($workers);
        
        if ($totalUtilization > 80) {
            return 'aggressive_balancing';
        } elseif ($totalUtilization > 60) {
            return 'moderate_balancing';
        } else {
            return 'minimal_balancing';
        }
    }

    /**
     * Calculate overall worker utilization.
     *
     * @param array $workers
     * @return float
     */
    protected function calculateOverallUtilization(array $workers): float
    {
        if (empty($workers)) {
            return 0;
        }

        $totalLoad = array_sum(array_column($workers, 'current_load'));
        $totalCapacity = array_sum(array_column($workers, 'capacity'));

        return $totalCapacity > 0 ? ($totalLoad / $totalCapacity) * 100 : 0;
    }

    /**
     * Analyze job failure patterns.
     *
     * @param array $job
     * @return array
     */
    protected function analyzeJobFailure(array $job): array
    {
        $errorMessage = $job['error_message'] ?? '';
        
        if (strpos($errorMessage, 'timeout') !== false) {
            return ['type' => 'timeout', 'severity' => 'medium'];
        } elseif (strpos($errorMessage, 'memory') !== false) {
            return ['type' => 'memory', 'severity' => 'high'];
        } elseif (strpos($errorMessage, 'network') !== false) {
            return ['type' => 'network', 'severity' => 'low'];
        } else {
            return ['type' => 'unknown', 'severity' => 'medium'];
        }
    }

    /**
     * Determine retry strategy for failed job.
     *
     * @param array $failureAnalysis
     * @return array
     */
    protected function determineRetryStrategy(array $failureAnalysis): array
    {
        $baseStrategy = [
            'should_retry' => true,
            'delay_seconds' => 60,
            'max_attempts' => 3,
            'success_probability' => 0.7
        ];

        switch ($failureAnalysis['type']) {
            case 'timeout':
                return array_merge($baseStrategy, [
                    'delay_seconds' => 120,
                    'max_attempts' => 2,
                    'success_probability' => 0.8
                ]);
            
            case 'memory':
                return array_merge($baseStrategy, [
                    'should_retry' => false,
                    'success_probability' => 0.1
                ]);
            
            case 'network':
                return array_merge($baseStrategy, [
                    'delay_seconds' => 30,
                    'max_attempts' => 5,
                    'success_probability' => 0.9
                ]);
            
            default:
                return $baseStrategy;
        }
    }
}