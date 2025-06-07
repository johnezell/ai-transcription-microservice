<?php

namespace App\Services;

use App\Models\TranscriptionLog;
use App\Models\AudioTestBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AudioTestErrorTrackingService
{
    /**
     * Error categories and their patterns.
     */
    private const ERROR_CATEGORIES = [
        'timeout' => [
            'patterns' => ['timeout', 'timed out', 'time limit exceeded'],
            'severity' => 'medium',
            'recoverable' => true,
            'suggested_action' => 'Increase timeout or optimize processing'
        ],
        'memory' => [
            'patterns' => ['memory', 'out of memory', 'memory limit', 'fatal error'],
            'severity' => 'high',
            'recoverable' => false,
            'suggested_action' => 'Increase memory allocation or optimize code'
        ],
        'network' => [
            'patterns' => ['network', 'connection', 'curl', 'http', 'socket'],
            'severity' => 'medium',
            'recoverable' => true,
            'suggested_action' => 'Check network connectivity and retry'
        ],
        'file_system' => [
            'patterns' => ['file not found', 'permission denied', 'disk space', 'storage'],
            'severity' => 'high',
            'recoverable' => false,
            'suggested_action' => 'Check file permissions and disk space'
        ],
        'audio_processing' => [
            'patterns' => ['ffmpeg', 'audio', 'codec', 'format not supported'],
            'severity' => 'medium',
            'recoverable' => false,
            'suggested_action' => 'Check audio file format and processing pipeline'
        ],
        'service_unavailable' => [
            'patterns' => ['service unavailable', '503', '502', '500', 'server error'],
            'severity' => 'high',
            'recoverable' => true,
            'suggested_action' => 'Check service health and retry'
        ]
    ];

    /**
     * Track and categorize errors from audio test jobs.
     *
     * @param int $days Number of days to analyze
     * @return array
     */
    public function trackErrors(int $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days);
        
        $errors = $this->getErrorData($startDate);
        $categorizedErrors = $this->categorizeErrors($errors);
        $errorTrends = $this->analyzeErrorTrends($startDate);
        $patterns = $this->detectErrorPatterns($categorizedErrors);
        $recommendations = $this->generateErrorRecommendations($categorizedErrors, $patterns);

        return [
            'summary' => [
                'total_errors' => count($errors),
                'error_rate' => $this->calculateErrorRate($startDate),
                'most_common_category' => $this->getMostCommonCategory($categorizedErrors),
                'period_days' => $days
            ],
            'categorized_errors' => $categorizedErrors,
            'error_trends' => $errorTrends,
            'patterns' => $patterns,
            'recommendations' => $recommendations,
            'generated_at' => now()->toISOString()
        ];
    }

    /**
     * Categorize errors based on error messages and patterns.
     *
     * @param array $errors
     * @return array
     */
    public function categorizeErrors(array $errors): array
    {
        $categorized = [];

        foreach ($errors as $error) {
            $category = $this->classifyError($error['error_message']);
            
            if (!isset($categorized[$category])) {
                $categorized[$category] = [
                    'count' => 0,
                    'errors' => [],
                    'severity' => self::ERROR_CATEGORIES[$category]['severity'] ?? 'medium',
                    'recoverable' => self::ERROR_CATEGORIES[$category]['recoverable'] ?? false,
                    'suggested_action' => self::ERROR_CATEGORIES[$category]['suggested_action'] ?? 'Review error details'
                ];
            }

            $categorized[$category]['count']++;
            $categorized[$category]['errors'][] = [
                'id' => $error['id'],
                'error_message' => $error['error_message'],
                'occurred_at' => $error['created_at'],
                'quality_level' => $error['test_quality_level'],
                'batch_id' => $error['audio_test_batch_id'],
                'processing_time' => $error['total_processing_duration_seconds']
            ];
        }

        // Sort categories by count (most common first)
        uasort($categorized, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return $categorized;
    }

    /**
     * Analyze error trends over time.
     *
     * @param Carbon $startDate
     * @return array
     */
    public function analyzeErrorTrends(Carbon $startDate): array
    {
        $dailyErrors = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('status', 'failed')
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as error_count,
                COUNT(DISTINCT test_quality_level) as quality_levels_affected
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $hourlyErrors = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('
                HOUR(created_at) as hour,
                COUNT(*) as error_count
            ')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        return [
            'daily_trends' => $dailyErrors->toArray(),
            'hourly_trends' => $hourlyErrors->toArray(),
            'trend_analysis' => $this->calculateTrendDirection($dailyErrors),
            'peak_error_hours' => $this->identifyPeakErrorHours($hourlyErrors)
        ];
    }

    /**
     * Detect error patterns and correlations.
     *
     * @param array $categorizedErrors
     * @return array
     */
    public function detectErrorPatterns(array $categorizedErrors): array
    {
        $patterns = [];

        // Quality level correlation
        $qualityLevelErrors = $this->analyzeQualityLevelErrors();
        if (!empty($qualityLevelErrors)) {
            $patterns['quality_level_correlation'] = $qualityLevelErrors;
        }

        // Batch size correlation
        $batchSizeErrors = $this->analyzeBatchSizeErrors();
        if (!empty($batchSizeErrors)) {
            $patterns['batch_size_correlation'] = $batchSizeErrors;
        }

        // Time-based patterns
        $timePatterns = $this->analyzeTimeBasedPatterns();
        if (!empty($timePatterns)) {
            $patterns['time_based_patterns'] = $timePatterns;
        }

        // Sequential failure patterns
        $sequentialPatterns = $this->analyzeSequentialFailures();
        if (!empty($sequentialPatterns)) {
            $patterns['sequential_failures'] = $sequentialPatterns;
        }

        return $patterns;
    }

    /**
     * Generate recommendations based on error analysis.
     *
     * @param array $categorizedErrors
     * @param array $patterns
     * @return array
     */
    public function generateErrorRecommendations(array $categorizedErrors, array $patterns): array
    {
        $recommendations = [];

        // Category-based recommendations
        foreach ($categorizedErrors as $category => $data) {
            if ($data['count'] > 5) { // Only recommend for frequent errors
                $recommendations[] = [
                    'type' => 'category_specific',
                    'category' => $category,
                    'priority' => $data['severity'] === 'high' ? 'high' : 'medium',
                    'recommendation' => $data['suggested_action'],
                    'affected_jobs' => $data['count'],
                    'impact' => $this->calculateImpact($data['count'])
                ];
            }
        }

        // Pattern-based recommendations
        if (isset($patterns['quality_level_correlation'])) {
            $worstQuality = array_keys($patterns['quality_level_correlation'])[0];
            $recommendations[] = [
                'type' => 'quality_optimization',
                'priority' => 'medium',
                'recommendation' => "Review {$worstQuality} quality level processing - highest error rate detected",
                'affected_quality' => $worstQuality,
                'impact' => 'medium'
            ];
        }

        if (isset($patterns['batch_size_correlation'])) {
            $recommendations[] = [
                'type' => 'batch_optimization',
                'priority' => 'medium',
                'recommendation' => 'Consider optimizing batch size limits based on error correlation',
                'impact' => 'medium'
            ];
        }

        // System-wide recommendations
        $errorRate = $this->calculateErrorRate(now()->subDays(7));
        if ($errorRate > 10) {
            $recommendations[] = [
                'type' => 'system_health',
                'priority' => 'high',
                'recommendation' => 'High error rate detected - investigate system health and resource allocation',
                'current_error_rate' => $errorRate,
                'impact' => 'high'
            ];
        }

        return $recommendations;
    }

    /**
     * Get performance impact analysis of errors.
     *
     * @param int $days
     * @return array
     */
    public function getPerformanceImpact(int $days = 7): array
    {
        $startDate = Carbon::now()->subDays($days);

        $impact = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_jobs,
                SUM(CASE WHEN status = "failed" THEN total_processing_duration_seconds ELSE 0 END) as wasted_processing_time,
                AVG(CASE WHEN status = "completed" THEN total_processing_duration_seconds END) as avg_successful_time,
                AVG(CASE WHEN status = "failed" THEN total_processing_duration_seconds END) as avg_failed_time
            ')
            ->first();

        $wastedResources = $this->calculateWastedResources($impact);
        $userImpact = $this->calculateUserImpact($startDate);

        return [
            'processing_impact' => [
                'total_jobs' => $impact->total_jobs ?? 0,
                'failed_jobs' => $impact->failed_jobs ?? 0,
                'failure_rate_percent' => $impact->total_jobs > 0 
                    ? round(($impact->failed_jobs / $impact->total_jobs) * 100, 2) 
                    : 0,
                'wasted_processing_hours' => round(($impact->wasted_processing_time ?? 0) / 3600, 2),
                'avg_successful_time_seconds' => round($impact->avg_successful_time ?? 0, 2),
                'avg_failed_time_seconds' => round($impact->avg_failed_time ?? 0, 2)
            ],
            'resource_impact' => $wastedResources,
            'user_impact' => $userImpact,
            'estimated_cost_impact' => $this->estimateCostImpact($wastedResources)
        ];
    }

    /**
     * Generate automated error reports.
     *
     * @param string $period
     * @return array
     */
    public function generateErrorReport(string $period = 'daily'): array
    {
        $days = match($period) {
            'hourly' => 1,
            'daily' => 1,
            'weekly' => 7,
            'monthly' => 30,
            default => 7
        };

        $errorData = $this->trackErrors($days);
        $performanceImpact = $this->getPerformanceImpact($days);
        
        $report = [
            'report_type' => $period,
            'generated_at' => now()->toISOString(),
            'period' => [
                'start_date' => now()->subDays($days)->toDateString(),
                'end_date' => now()->toDateString(),
                'days' => $days
            ],
            'executive_summary' => $this->generateExecutiveSummary($errorData, $performanceImpact),
            'error_analysis' => $errorData,
            'performance_impact' => $performanceImpact,
            'action_items' => $this->generateActionItems($errorData['recommendations'])
        ];

        // Cache the report
        Cache::put("error_report_{$period}", $report, now()->addHours(24));

        return $report;
    }

    /**
     * Get error data from database.
     *
     * @param Carbon $startDate
     * @return array
     */
    protected function getErrorData(Carbon $startDate): array
    {
        return DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('status', 'failed')
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('error_message')
            ->select([
                'id',
                'error_message',
                'test_quality_level',
                'audio_test_batch_id',
                'total_processing_duration_seconds',
                'created_at'
            ])
            ->get()
            ->toArray();
    }

    /**
     * Classify error based on message content.
     *
     * @param string $errorMessage
     * @return string
     */
    protected function classifyError(string $errorMessage): string
    {
        $errorMessage = strtolower($errorMessage);

        foreach (self::ERROR_CATEGORIES as $category => $config) {
            foreach ($config['patterns'] as $pattern) {
                if (strpos($errorMessage, strtolower($pattern)) !== false) {
                    return $category;
                }
            }
        }

        return 'unknown';
    }

    /**
     * Calculate error rate for given period.
     *
     * @param Carbon $startDate
     * @return float
     */
    protected function calculateErrorRate(Carbon $startDate): float
    {
        $totalJobs = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('created_at', '>=', $startDate)
            ->count();

        $failedJobs = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('status', 'failed')
            ->where('created_at', '>=', $startDate)
            ->count();

        return $totalJobs > 0 ? round(($failedJobs / $totalJobs) * 100, 2) : 0;
    }

    /**
     * Get most common error category.
     *
     * @param array $categorizedErrors
     * @return string
     */
    protected function getMostCommonCategory(array $categorizedErrors): string
    {
        if (empty($categorizedErrors)) {
            return 'none';
        }

        return array_keys($categorizedErrors)[0];
    }

    /**
     * Calculate trend direction from daily data.
     *
     * @param \Illuminate\Support\Collection $dailyErrors
     * @return array
     */
    protected function calculateTrendDirection($dailyErrors): array
    {
        if ($dailyErrors->count() < 2) {
            return ['direction' => 'insufficient_data', 'change' => 0];
        }

        $first = $dailyErrors->first();
        $last = $dailyErrors->last();
        $change = $last->error_count - $first->error_count;

        return [
            'direction' => $change > 0 ? 'increasing' : ($change < 0 ? 'decreasing' : 'stable'),
            'change' => $change,
            'percentage_change' => $first->error_count > 0 
                ? round(($change / $first->error_count) * 100, 2) 
                : 0
        ];
    }

    /**
     * Identify peak error hours.
     *
     * @param \Illuminate\Support\Collection $hourlyErrors
     * @return array
     */
    protected function identifyPeakErrorHours($hourlyErrors): array
    {
        if ($hourlyErrors->isEmpty()) {
            return [];
        }

        return $hourlyErrors->sortByDesc('error_count')->take(3)->values()->toArray();
    }

    /**
     * Analyze errors by quality level.
     *
     * @return array
     */
    protected function analyzeQualityLevelErrors(): array
    {
        $qualityErrors = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('
                test_quality_level,
                COUNT(*) as error_count,
                COUNT(*) * 100.0 / (
                    SELECT COUNT(*) 
                    FROM transcription_logs tl2 
                    WHERE tl2.is_test_extraction = 1 
                    AND tl2.test_quality_level = transcription_logs.test_quality_level
                    AND tl2.created_at >= ?
                ) as error_rate
            ', [now()->subDays(7)])
            ->groupBy('test_quality_level')
            ->orderBy('error_rate', 'desc')
            ->get();

        return $qualityErrors->pluck('error_rate', 'test_quality_level')->toArray();
    }

    /**
     * Analyze errors by batch size.
     *
     * @return array
     */
    protected function analyzeBatchSizeErrors(): array
    {
        $batchErrors = DB::table('transcription_logs')
            ->join('audio_test_batches', 'transcription_logs.audio_test_batch_id', '=', 'audio_test_batches.id')
            ->where('transcription_logs.is_test_extraction', true)
            ->where('transcription_logs.status', 'failed')
            ->where('transcription_logs.created_at', '>=', now()->subDays(7))
            ->selectRaw('
                CASE 
                    WHEN audio_test_batches.total_segments <= 10 THEN "small"
                    WHEN audio_test_batches.total_segments <= 50 THEN "medium"
                    ELSE "large"
                END as batch_size_category,
                COUNT(*) as error_count
            ')
            ->groupBy('batch_size_category')
            ->get();

        return $batchErrors->pluck('error_count', 'batch_size_category')->toArray();
    }

    /**
     * Analyze time-based error patterns.
     *
     * @return array
     */
    protected function analyzeTimeBasedPatterns(): array
    {
        $patterns = [];

        // Weekend vs weekday errors
        $weekendErrors = DB::table('transcription_logs')
            ->where('is_test_extraction', true)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(14))
            ->selectRaw('
                CASE 
                    WHEN DAYOFWEEK(created_at) IN (1, 7) THEN "weekend"
                    ELSE "weekday"
                END as day_type,
                COUNT(*) as error_count
            ')
            ->groupBy('day_type')
            ->get();

        if ($weekendErrors->count() > 1) {
            $patterns['weekend_vs_weekday'] = $weekendErrors->pluck('error_count', 'day_type')->toArray();
        }

        return $patterns;
    }

    /**
     * Analyze sequential failure patterns.
     *
     * @return array
     */
    protected function analyzeSequentialFailures(): array
    {
        // Find batches with high failure rates
        $sequentialFailures = DB::table('audio_test_batches')
            ->join('transcription_logs', 'audio_test_batches.id', '=', 'transcription_logs.audio_test_batch_id')
            ->where('audio_test_batches.created_at', '>=', now()->subDays(7))
            ->selectRaw('
                audio_test_batches.id,
                audio_test_batches.total_segments,
                COUNT(*) as total_jobs,
                SUM(CASE WHEN transcription_logs.status = "failed" THEN 1 ELSE 0 END) as failed_jobs
            ')
            ->groupBy('audio_test_batches.id', 'audio_test_batches.total_segments')
            ->havingRaw('failed_jobs > 0 AND (failed_jobs * 100.0 / total_jobs) > 50')
            ->get();

        return $sequentialFailures->map(function ($batch) {
            return [
                'batch_id' => $batch->id,
                'total_segments' => $batch->total_segments,
                'failure_rate' => round(($batch->failed_jobs / $batch->total_jobs) * 100, 2)
            ];
        })->toArray();
    }

    /**
     * Calculate impact level based on error count.
     *
     * @param int $errorCount
     * @return string
     */
    protected function calculateImpact(int $errorCount): string
    {
        if ($errorCount > 50) {
            return 'high';
        } elseif ($errorCount > 10) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Calculate wasted resources from failed jobs.
     *
     * @param object $impact
     * @return array
     */
    protected function calculateWastedResources($impact): array
    {
        $wastedHours = ($impact->wasted_processing_time ?? 0) / 3600;
        
        return [
            'wasted_cpu_hours' => round($wastedHours, 2),
            'wasted_memory_gb_hours' => round($wastedHours * 0.5, 2), // Estimate 500MB per job
            'estimated_cost_usd' => round($wastedHours * 0.10, 2) // Estimate $0.10 per CPU hour
        ];
    }

    /**
     * Calculate user impact from errors.
     *
     * @param Carbon $startDate
     * @return array
     */
    protected function calculateUserImpact(Carbon $startDate): array
    {
        $userImpact = DB::table('audio_test_batches')
            ->join('transcription_logs', 'audio_test_batches.id', '=', 'transcription_logs.audio_test_batch_id')
            ->where('audio_test_batches.created_at', '>=', $startDate)
            ->where('transcription_logs.status', 'failed')
            ->selectRaw('
                COUNT(DISTINCT audio_test_batches.user_id) as affected_users,
                COUNT(DISTINCT audio_test_batches.id) as affected_batches,
                AVG(audio_test_batches.total_segments) as avg_affected_batch_size
            ')
            ->first();

        return [
            'affected_users' => $userImpact->affected_users ?? 0,
            'affected_batches' => $userImpact->affected_batches ?? 0,
            'avg_affected_batch_size' => round($userImpact->avg_affected_batch_size ?? 0, 1)
        ];
    }

    /**
     * Estimate cost impact of errors.
     *
     * @param array $wastedResources
     * @return array
     */
    protected function estimateCostImpact(array $wastedResources): array
    {
        return [
            'direct_cost_usd' => $wastedResources['estimated_cost_usd'],
            'opportunity_cost_usd' => round($wastedResources['estimated_cost_usd'] * 1.5, 2),
            'total_estimated_impact_usd' => round($wastedResources['estimated_cost_usd'] * 2.5, 2)
        ];
    }

    /**
     * Generate executive summary for error report.
     *
     * @param array $errorData
     * @param array $performanceImpact
     * @return array
     */
    protected function generateExecutiveSummary(array $errorData, array $performanceImpact): array
    {
        return [
            'key_metrics' => [
                'total_errors' => $errorData['summary']['total_errors'],
                'error_rate_percent' => $errorData['summary']['error_rate'],
                'most_common_error' => $errorData['summary']['most_common_category'],
                'affected_users' => $performanceImpact['user_impact']['affected_users']
            ],
            'critical_issues' => $this->identifyCriticalIssues($errorData),
            'improvement_opportunities' => $this->identifyImprovementOpportunities($errorData),
            'next_steps' => $this->generateNextSteps($errorData['recommendations'])
        ];
    }

    /**
     * Identify critical issues from error data.
     *
     * @param array $errorData
     * @return array
     */
    protected function identifyCriticalIssues(array $errorData): array
    {
        $critical = [];

        if ($errorData['summary']['error_rate'] > 15) {
            $critical[] = 'High error rate exceeds acceptable threshold';
        }

        foreach ($errorData['categorized_errors'] as $category => $data) {
            if ($data['severity'] === 'high' && $data['count'] > 10) {
                $critical[] = "High severity {$category} errors require immediate attention";
            }
        }

        return $critical;
    }

    /**
     * Identify improvement opportunities.
     *
     * @param array $errorData
     * @return array
     */
    protected function identifyImprovementOpportunities(array $errorData): array
    {
        $opportunities = [];

        foreach ($errorData['categorized_errors'] as $category => $data) {
            if ($data['recoverable'] && $data['count'] > 5) {
                $opportunities[] = "Implement automatic retry for {$category} errors";
            }
        }

        return $opportunities;
    }

    /**
     * Generate next steps from recommendations.
     *
     * @param array $recommendations
     * @return array
     */
    protected function generateNextSteps(array $recommendations): array
    {
        $highPriority = array_filter($recommendations, fn($r) => $r['priority'] === 'high');
        
        return array_slice(array_column($highPriority, 'recommendation'), 0, 3);
    }

    /**
     * Generate action items from recommendations.
     *
     * @param array $recommendations
     * @return array
     */
    protected function generateActionItems(array $recommendations): array
    {
        return array_map(function ($rec) {
            return [
                'priority' => $rec['priority'],
                'action' => $rec['recommendation'],
                'category' => $rec['type'],
                'estimated_effort' => $this->estimateEffort($rec),
                'expected_impact' => $rec['impact'] ?? 'medium'
            ];
        }, $recommendations);
    }

    /**
     * Estimate effort required for recommendation.
     *
     * @param array $recommendation
     * @return string
     */
    protected function estimateEffort(array $recommendation): string
    {
        $effortMap = [
            'category_specific' => 'medium',
            'quality_optimization' => 'high',
            'batch_optimization' => 'medium',
            'system_health' => 'high'
        ];

        return $effortMap[$recommendation['type']] ?? 'medium';
    }
}