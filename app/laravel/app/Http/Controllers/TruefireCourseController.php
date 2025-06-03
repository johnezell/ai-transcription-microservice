<?php

namespace App\Http\Controllers;

use App\Models\TruefireCourse;
use App\Jobs\DownloadTruefireSegmentV2;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use GuzzleHttp\Client as GuzzleClient;
use App\Http\Controllers\Controller;

class TruefireCourseController extends Controller
{
    /**
     * Display a listing of TrueFire courses.
     */
    public function index(Request $request)
    {
        $search = $request->get('search', '');
        $perPage = 15; // Items per page
        
        // Create cache key based on search and page parameters (updated for S3)
        $cacheKey = 'truefire_courses_s3_index_' . md5($search . '_' . ($request->get('page', 1)) . '_' . $perPage);
        
        // Cache the results for 5 minutes with tags if supported
        $courses = $this->cacheWithTagsSupport(
            ['truefire_courses_s3_index'],
            $cacheKey,
            300,
            function () use ($search, $perPage, $request) {
                $query = TruefireCourse::withCount('segments')
                    ->withSum('segments', 'runtime');
                
                // Apply search filter if search term is provided
                if (!empty($search)) {
                    $query->where(function ($q) use ($search) {
                        $q->where('id', 'like', '%' . $search . '%')
                          ->orWhere('title', 'like', '%' . $search . '%');
                    });
                }
                
                $courses = $query->paginate($perPage);
                
                // Append search parameter to pagination links
                $courses->appends($request->query());
                
                return $courses;
            }
        );
        
        return Inertia::render('TruefireCourses/Index', [
            'courses' => $courses,
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    /**
     * Display the specified TrueFire course.
     */
    public function show(TruefireCourse $truefireCourse)
    {
        // Cache the course data for 2 minutes (updated for S3)
        $cacheKey = 'truefire_course_s3_show_' . $truefireCourse->id;
        
        $courseData = Cache::remember($cacheKey, 120, function () use ($truefireCourse) {
            $course = $truefireCourse->load(['channels.segments']);
            
            // Set up course directory path for checking downloaded files
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            
            // Generate signed URLs for all segments and include download status
            $segmentsWithSignedUrls = [];
            foreach ($course->channels as $channel) {
                foreach ($channel->segments as $segment) {
                    // Check for both new format (segmentId.mp4) and legacy format (segment-segmentId.mp4)
                    $newFilename = "{$segment->id}.mp4";
                    $legacyFilename = "segment-{$segment->id}.mp4";
                    $newFilePath = "{$courseDir}/{$newFilename}";
                    $legacyFilePath = "{$courseDir}/{$legacyFilename}";
                    
                    $isNewFormatDownloaded = Storage::disk('local')->exists($newFilePath);
                    $isLegacyFormatDownloaded = Storage::disk('local')->exists($legacyFilePath);
                    $isDownloaded = $isNewFormatDownloaded || $isLegacyFormatDownloaded;
                    
                    // Use the format that exists, prefer new format
                    $actualFilePath = $isNewFormatDownloaded ? $newFilePath : ($isLegacyFormatDownloaded ? $legacyFilePath : $newFilePath);
                    
                    try {
                        $segmentsWithSignedUrls[] = [
                            'id' => $segment->id,
                            'channel_id' => $channel->id,
                            'channel_name' => $channel->name ?? $channel->title ?? "Channel #{$channel->id}",
                            'video' => $segment->video,
                            'title' => $segment->title ?? "Segment #{$segment->id}",
                            'signed_url' => $segment->getSignedUrl(),
                            'is_downloaded' => $isDownloaded,
                            'file_size' => $isDownloaded ? Storage::disk('local')->size($actualFilePath) : null,
                            'downloaded_at' => $isDownloaded ? Storage::disk('local')->lastModified($actualFilePath) : null,
                        ];
                    } catch (\Exception $e) {
                        \Log::warning('Failed to generate signed URL for segment', [
                            'segment_id' => $segment->id,
                            'error' => $e->getMessage()
                        ]);
                        $segmentsWithSignedUrls[] = [
                            'id' => $segment->id,
                            'channel_id' => $channel->id,
                            'channel_name' => $channel->name ?? $channel->title ?? "Channel #{$channel->id}",
                            'video' => $segment->video,
                            'title' => $segment->title ?? "Segment #{$segment->id}",
                            'signed_url' => null,
                            'error' => 'Failed to generate signed URL',
                            'is_downloaded' => $isDownloaded,
                            'file_size' => $isDownloaded ? Storage::disk('local')->size($actualFilePath) : null,
                            'downloaded_at' => $isDownloaded ? Storage::disk('local')->lastModified($actualFilePath) : null,
                        ];
                    }
                }
            }
            
            return [
                'course' => $course,
                'segmentsWithSignedUrls' => $segmentsWithSignedUrls
            ];
        });
        
        return Inertia::render('TruefireCourses/Show', $courseData);
    }

    /**
     * Download all videos from a TrueFire course to local storage.
     * Uses Laravel queues for background processing.
     */
    public function downloadAll(TruefireCourse $truefireCourse, Request $request)
    {
        try {
            // Load all segments for the course through channels (same as downloadStatus method)
            $course = $truefireCourse->load(['channels.segments']);
            
            // Collect all segments from all channels
            $allSegments = collect();
            foreach ($course->channels as $channel) {
                $allSegments = $allSegments->merge($channel->segments);
            }
            
            // Check if course has any segments
            if ($allSegments->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No segments found for this course.',
                    'stats' => [
                        'total_segments' => 0,
                        'already_downloaded' => 0,
                        'queued_downloads' => 0
                    ]
                ], 404);
            }

            // Check if this is a test mode (limit to 1 file for faster testing)
            $testMode = $request->get('test', false);
            $segments = $testMode ? $allSegments->take(1) : $allSegments;

            $courseDir = "truefire-courses/{$truefireCourse->id}";
            
            // Ensure course directory exists
            Storage::disk('local')->makeDirectory($courseDir);

            $stats = [
                'total_segments' => $segments->count(),
                'already_downloaded' => 0,
                'queued_downloads' => 0
            ];

            // Initialize download statistics cache
            $statsKey = "download_stats_{$truefireCourse->id}";
            $initialStats = ['success' => 0, 'failed' => 0, 'skipped' => 0];
            Cache::put($statsKey, $initialStats, 3600);
            
            Log::info('Starting background download jobs for TrueFire course', [
                'course_id' => $truefireCourse->id,
                'course_title' => $truefireCourse->title ?? "Course #{$truefireCourse->id}",
                'total_segments' => $stats['total_segments'],
                'test_mode' => $testMode,
                'storage_path' => storage_path("app/{$courseDir}"),
                'channels_count' => $course->channels->count(),
                'all_segments_count' => $allSegments->count(),
                'segments_to_process' => $segments->count(),
                'stats_cache_key' => $statsKey
            ]);

            // Dispatch jobs for each segment
            foreach ($segments as $segment) {
                // Check for both new format (segmentId.mp4) and legacy format (segment-segmentId.mp4)
                $newFilename = "{$segment->id}.mp4";
                $legacyFilename = "segment-{$segment->id}.mp4";
                $newFilePath = "{$courseDir}/{$newFilename}";
                $legacyFilePath = "{$courseDir}/{$legacyFilename}";
                
                $isNewFormatDownloaded = Storage::disk('local')->exists($newFilePath);
                $isLegacyFormatDownloaded = Storage::disk('local')->exists($legacyFilePath);
                $isAlreadyDownloaded = $isNewFormatDownloaded || $isLegacyFormatDownloaded;
                
                // Check if file already exists in either format
                if ($isAlreadyDownloaded) {
                    $stats['already_downloaded']++;
                    $existingFilePath = $isNewFormatDownloaded ? $newFilePath : $legacyFilePath;
                    Log::debug("Segment already downloaded, skipping job", [
                        'segment_id' => $segment->id,
                        'file_path' => $existingFilePath
                    ]);
                    continue;
                }
                
                // Use new filename format for new downloads
                $filename = $newFilename;
                $filePath = $newFilePath;

                try {
                    // Generate signed URL
                    $signedUrl = $segment->getSignedUrl();
                    
                    // Track this segment as queued
                    $queuedKey = "queued_segments_{$truefireCourse->id}";
                    $queuedSegments = Cache::get($queuedKey, []);
                    $queuedSegments[] = $segment->id;
                    Cache::put($queuedKey, $queuedSegments, 3600); // Store for 1 hour
                    
                    // Dispatch background job with improved V2 implementation
                    DownloadTruefireSegmentV2::dispatch($segment, $courseDir, $signedUrl, $course->id);
                    $stats['queued_downloads']++;
                    
                    Log::debug("Queued download job for segment", [
                        'segment_id' => $segment->id,
                        'signed_url' => substr($signedUrl, 0, 100) . '...',
                        'queued_segments_count' => count($queuedSegments)
                    ]);
                    
                } catch (\Exception $e) {
                    Log::error('Failed to queue download job for segment', [
                        'course_id' => $truefireCourse->id,
                        'segment_id' => $segment->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Log final results
            Log::info('Queued background download jobs for TrueFire course', [
                'course_id' => $truefireCourse->id,
                'stats' => $stats
            ]);

            $message = "Download jobs queued: {$stats['queued_downloads']} files queued for download, " .
                      "{$stats['already_downloaded']} already existed. " .
                      "Downloads will continue in the background.";

            // Clear caches related to this course
            $this->clearCourseCache($truefireCourse->id);

            return response()->json([
                'success' => true,
                'message' => $message,
                'stats' => $stats,
                'storage_path' => storage_path("app/{$courseDir}"),
                'background_processing' => true
            ]);

        } catch (\Exception $e) {
            Log::error('Error queuing download jobs for TrueFire course', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while queuing download jobs.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'stats' => [
                    'total_segments' => 0,
                    'already_downloaded' => 0,
                    'queued_downloads' => 0
                ]
            ], 500);
        }
    }

    /**
     * Get download status for a course - which segments are already downloaded
     */
    public function downloadStatus(TruefireCourse $truefireCourse)
    {
        try {
            // Cache download status for 1 minute (updated for S3)
            $cacheKey = 'truefire_s3_download_status_' . $truefireCourse->id;
            
            $status = Cache::remember($cacheKey, 60, function () use ($truefireCourse) {
                $course = $truefireCourse->load(['channels.segments']);
                $courseDir = "truefire-courses/{$truefireCourse->id}";
                
                // Collect all segments from all channels
                $allSegments = collect();
                foreach ($course->channels as $channel) {
                    $allSegments = $allSegments->merge($channel->segments);
                }
                
                $status = [
                    'course_id' => $truefireCourse->id,
                    'total_segments' => $allSegments->count(),
                    'downloaded_segments' => 0,
                    'storage_path' => storage_path("app/{$courseDir}"),
                    'segments' => []
                ];

                foreach ($allSegments as $segment) {
                    // Check for both new format (segmentId.mp4) and legacy format (segment-segmentId.mp4)
                    $newFilename = "{$segment->id}.mp4";
                    $legacyFilename = "segment-{$segment->id}.mp4";
                    $newFilePath = "{$courseDir}/{$newFilename}";
                    $legacyFilePath = "{$courseDir}/{$legacyFilename}";
                    
                    $isNewFormatDownloaded = Storage::disk('local')->exists($newFilePath);
                    $isLegacyFormatDownloaded = Storage::disk('local')->exists($legacyFilePath);
                    $isDownloaded = $isNewFormatDownloaded || $isLegacyFormatDownloaded;
                    
                    // Use the format that exists, prefer new format
                    $actualFilename = $isNewFormatDownloaded ? $newFilename : ($isLegacyFormatDownloaded ? $legacyFilename : $newFilename);
                    $actualFilePath = $isNewFormatDownloaded ? $newFilePath : ($isLegacyFormatDownloaded ? $legacyFilePath : $newFilePath);
                    
                    if ($isDownloaded) {
                        $status['downloaded_segments']++;
                    }
                    
                    $status['segments'][] = [
                        'segment_id' => $segment->id,
                        'title' => $segment->title ?? "Segment #{$segment->id}",
                        'filename' => $actualFilename,
                        'is_downloaded' => $isDownloaded,
                        'file_size' => $isDownloaded ? Storage::disk('local')->size($actualFilePath) : null,
                        'downloaded_at' => $isDownloaded ? Storage::disk('local')->lastModified($actualFilePath) : null
                    ];
                }
                
                return $status;
            });

            return response()->json($status);

        } catch (\Exception $e) {
            Log::error('Error getting download status for TrueFire course', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error getting download status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get download statistics from cache (used for real-time progress tracking)
     */
    public function downloadStats(TruefireCourse $truefireCourse)
    {
        try {
            $key = "download_stats_{$truefireCourse->id}";
            $stats = Cache::get($key, ['success' => 0, 'failed' => 0, 'skipped' => 0]);
            
            Log::debug("Retrieved download stats for course {$truefireCourse->id}", [
                'cache_key' => $key,
                'stats' => $stats,
                'cache_exists' => Cache::has($key)
            ]);
            
            return response()->json($stats);
            
        } catch (\Exception $e) {
            Log::error('Error getting download stats for TrueFire course', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Clear caches related to a specific course
     */
    private function clearCourseCache($courseId)
    {
        // Clear course show cache (both old CloudFront and new S3 keys)
        Cache::forget('truefire_course_show_' . $courseId);
        Cache::forget('truefire_course_s3_show_' . $courseId);
        
        // Clear download status cache (both old and new keys)
        Cache::forget('truefire_download_status_' . $courseId);
        Cache::forget('truefire_s3_download_status_' . $courseId);
        
        // Clear index caches - try tags first, fallback to individual keys
        $this->clearIndexCaches();
        
        Log::debug('Cleared caches for course (CloudFront and S3)', ['course_id' => $courseId]);
    }

    /**
     * Clear all course-related caches (useful after downloads complete)
     */
    public function clearAllCaches()
    {
        try {
            // Clear index caches
            $this->clearIndexCaches();
            
            // Clear all show and status caches by pattern (if using Redis)
            if (config('cache.default') === 'redis') {
                try {
                    $redis = Cache::getRedis();
                    $keys = $redis->keys('*truefire_course_show_*');
                    if (!empty($keys)) {
                        $redis->del($keys);
                    }
                    
                    $keys = $redis->keys('*truefire_download_status_*');
                    if (!empty($keys)) {
                        $redis->del($keys);
                    }
                } catch (\Exception $e) {
                    Log::warning('Could not clear Redis keys by pattern', ['error' => $e->getMessage()]);
                }
            }
            
            Log::info('Cleared all TrueFire course caches');
            
            return response()->json([
                'success' => true,
                'message' => 'All TrueFire course caches cleared successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error clearing TrueFire course caches', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error clearing caches',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Warm up the cache by pre-loading the first page of courses
     */
    public function warmCache()
    {
        try {
            // Warm up the first page of courses (no search)
            $perPage = 15;
            $cacheKey = 'truefire_courses_index_' . md5('_1_' . $perPage);
            
            $this->cacheWithTagsSupport(
                ['truefire_courses_index'], 
                $cacheKey, 
                300, 
                function () use ($perPage) {
                    $query = TruefireCourse::withCount('segments')
                        ->withSum('segments', 'runtime');
                    
                    $courses = $query->paginate($perPage);
                    
                    return $courses;
                }
            );
            
            Log::info('Cache warmed for TrueFire courses index');
            
            return response()->json([
                'success' => true,
                'message' => 'Cache warmed successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error warming TrueFire course cache', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error warming cache',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Helper method to cache with tagging support and fallback
     */
    private function cacheWithTagsSupport($tags, $cacheKey, $duration, $callback)
    {
        try {
            // Try using cache tags (works with Redis, Memcached)
            return Cache::tags($tags)->remember($cacheKey, $duration, $callback);
        } catch (\Exception $e) {
            // Fallback to regular caching without tags
            Log::debug('Cache tagging not supported, falling back to regular cache', [
                'error' => $e->getMessage(),
                'cache_driver' => config('cache.default')
            ]);
            return Cache::remember($cacheKey, $duration, $callback);
        }
    }

    /**
     * Clear index caches with tag support and fallback
     */
    private function clearIndexCaches()
    {
        try {
            // Try clearing with tags first (both old and new)
            Cache::tags(['truefire_courses_index'])->flush();
            Cache::tags(['truefire_courses_s3_index'])->flush();
        } catch (\Exception $e) {
            // Fallback: manually clear known cache keys
            Log::debug('Cache tag flush not supported, clearing individual keys', [
                'error' => $e->getMessage()
            ]);
            
            // Clear common cache patterns manually (both old CloudFront and new S3)
            $patterns = [
                'truefire_courses_index_', // Old CloudFront pattern
                'truefire_courses_s3_index_', // New S3 pattern
            ];
            
            foreach ($patterns as $pattern) {
                // For file or database cache, we'll need to clear specific keys
                // This is a limitation but better than nothing
                for ($page = 1; $page <= 10; $page++) { // Clear first 10 pages
                    $searches = ['', 'test', 'guitar', 'bass']; // Common search terms
                    foreach ($searches as $search) {
                        $key = $pattern . md5($search . '_' . $page . '_15');
                        Cache::forget($key);
                    }
                }
            }
        }
    }

    /**
     * Get queue status for segments (queued, processing, completed)
     */
    public function queueStatus(TruefireCourse $truefireCourse)
    {
        try {
            // Get all segments for this course
            $course = $truefireCourse->load(['channels.segments']);
            $allSegments = collect();
            foreach ($course->channels as $channel) {
                $allSegments = $allSegments->merge($channel->segments);
            }
            
            $segmentIds = $allSegments->pluck('id')->toArray();
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            
            // Get queued jobs from database (if using database queue driver)
            $queuedJobs = collect();
            $debugInfo = [
                'queue_driver' => config('queue.default'),
                'jobs_table_count' => 0,
                'checked_queues' => [],
                'payload_debug' => []
            ];
            
            if (config('queue.default') === 'database') {
                // Check all jobs in the database, regardless of queue name first
                $allJobs = \DB::table('jobs')->get();
                $debugInfo['jobs_table_count'] = $allJobs->count();
                
                // Check what queue names exist
                $existingQueues = $allJobs->pluck('queue')->unique()->values()->toArray();
                $debugInfo['checked_queues'] = $existingQueues;
                
                // Try multiple possible queue names
                $possibleQueues = ['downloads', 'default', null, ''];
                foreach ($possibleQueues as $queueName) {
                    $jobs = \DB::table('jobs');
                    
                    if ($queueName === null) {
                        // Check jobs with null queue
                        $jobs = $jobs->whereNull('queue');
                    } elseif ($queueName === '') {
                        // Check jobs with empty string queue
                        $jobs = $jobs->where('queue', '=', '');
                    } else {
                        // Check jobs with specific queue name
                        $jobs = $jobs->where('queue', $queueName);
                    }
                    
                    $jobs = $jobs->whereNotNull('payload')->get();
                    
                    foreach ($jobs as $job) {
                        try {
                            $payload = json_decode($job->payload, true);
                            
                            // Log payload structure for debugging
                            if (count($debugInfo['payload_debug']) < 3) {
                                $debugInfo['payload_debug'][] = [
                                    'queue' => $job->queue,
                                    'payload_keys' => array_keys($payload),
                                    'has_segment' => isset($payload['data']['segment']),
                                    'job_class' => $payload['displayName'] ?? 'unknown'
                                ];
                            }
                            
                            if (isset($payload['data']['segment'])) {
                                $segmentData = unserialize($payload['data']['segment']);
                                if (in_array($segmentData->id, $segmentIds)) {
                                    $queuedJobs->push($job);
                                }
                            }
                        } catch (\Exception $e) {
                            // Skip malformed payloads
                            continue;
                        }
                    }
                }
            }
            
            // Get processing status from cache (our custom tracking)
            $processingKey = "processing_segments_{$truefireCourse->id}";
            $processingSegments = Cache::get($processingKey, []);
            
            // Get failed jobs from database
            $failedJobs = collect();
            if (config('queue.default') === 'database') {
                $failedJobs = \DB::table('failed_jobs')
                    ->whereNotNull('payload')
                    ->get()
                    ->filter(function ($job) use ($segmentIds) {
                        try {
                            $payload = json_decode($job->payload, true);
                            if (isset($payload['data']['segment'])) {
                                $segmentData = unserialize($payload['data']['segment']);
                                return in_array($segmentData->id, $segmentIds);
                            }
                        } catch (\Exception $e) {
                            // Skip malformed payloads
                        }
                        return false;
                    });
            }
            
            // Build status for each segment
            $segmentStatuses = [];
            foreach ($allSegments as $segment) {
                // Check if file exists
                $filename = "{$segment->id}.mp4";
                $filePath = "{$courseDir}/{$filename}";
                $isDownloaded = Storage::disk('local')->exists($filePath);
                
                // Determine status
                $status = 'not_started';
                $failedAt = null;
                $failureReason = null;
                
                if ($isDownloaded) {
                    $status = 'completed';
                } elseif (in_array($segment->id, $processingSegments)) {
                    $status = 'processing';
                } else {
                    // Check if this segment failed
                    $failedJob = $failedJobs->first(function ($job) use ($segment) {
                        try {
                            $payload = json_decode($job->payload, true);
                            if (isset($payload['data']['segment'])) {
                                $segmentData = unserialize($payload['data']['segment']);
                                return $segmentData->id === $segment->id;
                            }
                        } catch (\Exception $e) {
                            // Skip malformed payloads
                        }
                        return false;
                    });
                    
                    if ($failedJob) {
                        $status = 'failed';
                        $failedAt = $failedJob->failed_at;
                        $failureReason = $failedJob->exception;
                    } elseif ($queuedJobs->isNotEmpty()) {
                        // Check if this segment is in queued jobs
                        $isQueued = $queuedJobs->contains(function ($job) use ($segment) {
                            try {
                                $payload = json_decode($job->payload, true);
                                if (isset($payload['data']['segment'])) {
                                    $segmentData = unserialize($payload['data']['segment']);
                                    return $segmentData->id === $segment->id;
                                }
                            } catch (\Exception $e) {
                                // Skip malformed payloads
                            }
                            return false;
                        });
                        if ($isQueued) {
                            $status = 'queued';
                        }
                    }
                }
                
                $segmentStatuses[] = [
                    'segment_id' => $segment->id,
                    'title' => $segment->title ?? "Segment #{$segment->id}",
                    'status' => $status,
                    'file_size' => $isDownloaded ? Storage::disk('local')->size($filePath) : null,
                    'failed_at' => $failedAt,
                    'failure_reason' => $failureReason ? substr($failureReason, 0, 200) . '...' : null, // Truncate long error messages
                ];
            }
            
            $statusCounts = [
                'completed' => collect($segmentStatuses)->where('status', 'completed')->count(),
                'processing' => collect($segmentStatuses)->where('status', 'processing')->count(),
                'queued' => collect($segmentStatuses)->where('status', 'queued')->count(),
                'not_started' => collect($segmentStatuses)->where('status', 'not_started')->count(),
                'failed' => collect($segmentStatuses)->where('status', 'failed')->count(),
            ];
            
            return response()->json([
                'course_id' => $truefireCourse->id,
                'total_segments' => count($segmentStatuses),
                'status_counts' => $statusCounts,
                'segments' => $segmentStatuses,
                'queue_driver' => config('queue.default'),
                'using_database_queue' => config('queue.default') === 'database',
                'debug_info' => config('app.debug') ? $debugInfo : null // Only include debug info if app debug is enabled
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting queue status for TrueFire course', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error getting queue status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Download a specific segment
     */
    public function downloadSegment(TruefireCourse $truefireCourse, $segmentId)
    {
        try {
            // Load the course with segments to find the requested segment
            $course = $truefireCourse->load(['channels.segments']);
            
            // Find the specific segment
            $segment = null;
            foreach ($course->channels as $channel) {
                $foundSegment = $channel->segments->where('id', $segmentId)->first();
                if ($foundSegment) {
                    $segment = $foundSegment;
                    break;
                }
            }
            
            if (!$segment) {
                return response()->json([
                    'success' => false,
                    'message' => "Segment {$segmentId} not found in this course."
                ], 404);
            }
            
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            
            // Ensure course directory exists
            Storage::disk('local')->makeDirectory($courseDir);
            
            // Check if file already exists
            $filename = "{$segment->id}.mp4";
            $filePath = "{$courseDir}/{$filename}";
            $isAlreadyDownloaded = Storage::disk('local')->exists($filePath);
            
            Log::info('Starting individual segment download', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segment->id,
                'already_downloaded' => $isAlreadyDownloaded,
                'file_path' => $filePath
            ]);
            
            try {
                // Generate signed URL
                $signedUrl = $segment->getSignedUrl();
                
                // Track this segment as queued
                $queuedKey = "queued_segments_{$truefireCourse->id}";
                $queuedSegments = Cache::get($queuedKey, []);
                $queuedSegments[] = $segment->id;
                Cache::put($queuedKey, $queuedSegments, 3600); // Store for 1 hour
                
                // Dispatch background job
                DownloadTruefireSegmentV2::dispatch($segment, $courseDir, $signedUrl, $course->id);
                
                Log::info("Queued download job for individual segment", [
                    'segment_id' => $segment->id,
                    'course_id' => $truefireCourse->id,
                    'signed_url' => substr($signedUrl, 0, 100) . '...'
                ]);
                
                // Clear caches related to this course
                $this->clearCourseCache($truefireCourse->id);
                
                return response()->json([
                    'success' => true,
                    'message' => "Download job queued for segment {$segment->id}. The file will be downloaded in the background.",
                    'segment' => [
                        'id' => $segment->id,
                        'title' => $segment->title ?? "Segment #{$segment->id}",
                        'filename' => $filename,
                        'already_downloaded' => $isAlreadyDownloaded
                    ],
                    'storage_path' => storage_path("app/{$courseDir}"),
                    'background_processing' => true
                ]);
                
            } catch (\Exception $e) {
                Log::error('Failed to queue download job for individual segment', [
                    'course_id' => $truefireCourse->id,
                    'segment_id' => $segment->id,
                    'error' => $e->getMessage()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to generate signed URL or queue download job.',
                    'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
                ], 500);
            }
            
        } catch (\Exception $e) {
            Log::error('Error downloading individual segment', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while queuing the download job.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}