<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class DownloadMonitorController extends Controller
{
    /**
     * Display the download monitoring dashboard
     */
    public function index()
    {
        $stats = $this->getOverallStats();
        $courseStats = $this->getCourseStats();
        $systemHealth = $this->getSystemHealth();
        
        return Inertia::render('DownloadMonitor/Dashboard', [
            'stats' => $stats,
            'courseStats' => $courseStats,
            'systemHealth' => $systemHealth
        ]);
    }

    /**
     * Get real-time download statistics via API
     */
    public function stats()
    {
        return response()->json([
            'overall' => $this->getOverallStats(),
            'courses' => $this->getCourseStats(),
            'system' => $this->getSystemHealth(),
            'queue' => $this->getQueueStats()
        ]);
    }

    /**
     * Get overall download statistics
     */
    private function getOverallStats(): array
    {
        // Get all course stats from cache
        $allStats = ['success' => 0, 'failed' => 0, 'skipped' => 0];
        $cacheKeys = Cache::get('download_stats_keys', []);
        
        foreach ($cacheKeys as $key) {
            $courseStats = Cache::get($key, ['success' => 0, 'failed' => 0, 'skipped' => 0]);
            $allStats['success'] += $courseStats['success'];
            $allStats['failed'] += $courseStats['failed'];
            $allStats['skipped'] += $courseStats['skipped'];
        }
        
        $totalProcessed = $allStats['success'] + $allStats['failed'] + $allStats['skipped'];
        $successRate = $totalProcessed > 0 ? round(($allStats['success'] / $totalProcessed) * 100, 2) : 0;
        
        return [
            'total_processed' => $totalProcessed,
            'successful' => $allStats['success'],
            'failed' => $allStats['failed'],
            'skipped' => $allStats['skipped'],
            'success_rate' => $successRate,
            'estimated_total' => $this->getEstimatedTotal(),
            'completion_percentage' => $this->getCompletionPercentage(),
            'eta' => $this->calculateETA()
        ];
    }

    /**
     * Get per-course download statistics
     */
    private function getCourseStats(): array
    {
        $courseStats = [];
        $cacheKeys = Cache::get('download_stats_keys', []);
        
        foreach ($cacheKeys as $key) {
            if (preg_match('/download_stats_(\d+)/', $key, $matches)) {
                $courseId = $matches[1];
                $stats = Cache::get($key, ['success' => 0, 'failed' => 0, 'skipped' => 0]);
                
                $courseStats[] = [
                    'course_id' => $courseId,
                    'course_name' => $this->getCourseName($courseId),
                    'stats' => $stats,
                    'total' => $stats['success'] + $stats['failed'] + $stats['skipped'],
                    'success_rate' => $this->calculateSuccessRate($stats)
                ];
            }
        }
        
        // Sort by total downloads descending
        usort($courseStats, function($a, $b) {
            return $b['total'] <=> $a['total'];
        });
        
        return array_slice($courseStats, 0, 20); // Top 20 courses
    }

    /**
     * Get system health metrics
     */
    private function getSystemHealth(): array
    {
        return [
            'active_downloads' => Cache::get('download_rate_limit', 0),
            'max_concurrent' => 5,
            'storage_usage' => $this->getStorageUsage(),
            'queue_health' => $this->getQueueHealth(),
            'memory_usage' => $this->getMemoryUsage(),
            'uptime' => $this->getSystemUptime()
        ];
    }

    /**
     * Get queue statistics
     */
    private function getQueueStats(): array
    {
        try {
            $pendingJobs = DB::table('jobs')->where('queue', 'downloads')->count();
            $failedJobs = DB::table('failed_jobs')->count();
            
            return [
                'pending' => $pendingJobs,
                'failed' => $failedJobs,
                'processing_rate' => $this->getProcessingRate(),
                'average_job_time' => $this->getAverageJobTime()
            ];
        } catch (\Exception $e) {
            return [
                'pending' => 0,
                'failed' => 0,
                'processing_rate' => 0,
                'average_job_time' => 0,
                'error' => 'Unable to fetch queue stats'
            ];
        }
    }

    /**
     * Get storage usage information
     */
    private function getStorageUsage(): array
    {
        try {
            $storagePath = storage_path('app/truefire-courses');
            $totalSize = 0;
            $fileCount = 0;
            
            if (is_dir($storagePath)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($storagePath)
                );
                
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $totalSize += $file->getSize();
                        $fileCount++;
                    }
                }
            }
            
            return [
                'total_size_bytes' => $totalSize,
                'total_size_gb' => round($totalSize / (1024 * 1024 * 1024), 2),
                'file_count' => $fileCount,
                'average_file_size_mb' => $fileCount > 0 ? round($totalSize / $fileCount / (1024 * 1024), 2) : 0
            ];
        } catch (\Exception $e) {
            return [
                'total_size_bytes' => 0,
                'total_size_gb' => 0,
                'file_count' => 0,
                'average_file_size_mb' => 0,
                'error' => 'Unable to calculate storage usage'
            ];
        }
    }

    /**
     * Calculate success rate for stats array
     */
    private function calculateSuccessRate(array $stats): float
    {
        $total = $stats['success'] + $stats['failed'] + $stats['skipped'];
        return $total > 0 ? round(($stats['success'] / $total) * 100, 2) : 0;
    }

    /**
     * Get course name by ID
     */
    private function getCourseName(int $courseId): string
    {
        try {
            $course = DB::table('truefire_courses')->where('id', $courseId)->first();
            return $course ? $course->title : "Course #{$courseId}";
        } catch (\Exception $e) {
            return "Course #{$courseId}";
        }
    }

    /**
     * Estimate total number of videos to download
     */
    private function getEstimatedTotal(): int
    {
        try {
            return DB::table('segments')
                ->join('truefire_courses', 'segments.course_id', '=', 'truefire_courses.id')
                ->count();
        } catch (\Exception $e) {
            return 100000; // Default estimate
        }
    }

    /**
     * Calculate completion percentage
     */
    private function getCompletionPercentage(): float
    {
        $stats = $this->getOverallStats();
        $estimated = $stats['estimated_total'];
        
        if ($estimated == 0) return 0;
        
        return round(($stats['total_processed'] / $estimated) * 100, 2);
    }

    /**
     * Calculate estimated time to completion
     */
    private function calculateETA(): array
    {
        $processingRate = $this->getProcessingRate();
        $remaining = $this->getEstimatedTotal() - $this->getOverallStats()['total_processed'];
        
        if ($processingRate == 0 || $remaining <= 0) {
            return ['hours' => 0, 'minutes' => 0, 'formatted' => 'Complete'];
        }
        
        $hoursRemaining = $remaining / $processingRate;
        $hours = floor($hoursRemaining);
        $minutes = round(($hoursRemaining - $hours) * 60);
        
        return [
            'hours' => $hours,
            'minutes' => $minutes,
            'formatted' => $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m"
        ];
    }

    /**
     * Get processing rate (downloads per hour)
     */
    private function getProcessingRate(): float
    {
        // Get processing rate from cache or calculate based on recent activity
        $rate = Cache::get('processing_rate', 0);
        
        if ($rate == 0) {
            // Estimate based on system capacity
            $activeWorkers = 5; // Number of workers
            $avgJobTime = 5; // 5 minutes per job
            $rate = ($activeWorkers * 60) / $avgJobTime; // Jobs per hour
        }
        
        return $rate;
    }

    /**
     * Get average job processing time
     */
    private function getAverageJobTime(): float
    {
        // This would ideally be tracked in the job itself
        return Cache::get('average_job_time', 5.0); // Default 5 minutes
    }

    /**
     * Get queue health status
     */
    private function getQueueHealth(): string
    {
        $pendingJobs = DB::table('jobs')->where('queue', 'downloads')->count();
        $failedJobs = DB::table('failed_jobs')->count();
        
        if ($failedJobs > 100) return 'critical';
        if ($pendingJobs > 1000) return 'warning';
        if ($pendingJobs > 0) return 'active';
        
        return 'healthy';
    }

    /**
     * Get memory usage information
     */
    private function getMemoryUsage(): array
    {
        return [
            'current_mb' => round(memory_get_usage(true) / (1024 * 1024), 2),
            'peak_mb' => round(memory_get_peak_usage(true) / (1024 * 1024), 2),
            'limit_mb' => ini_get('memory_limit')
        ];
    }

    /**
     * Get system uptime
     */
    private function getSystemUptime(): string
    {
        $startTime = Cache::get('system_start_time', time());
        $uptime = time() - $startTime;
        
        $hours = floor($uptime / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        
        return "{$hours}h {$minutes}m";
    }
}