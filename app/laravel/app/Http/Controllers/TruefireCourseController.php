<?php

namespace App\Http\Controllers;

use App\Models\TruefireCourse;
use App\Jobs\DownloadTruefireSegmentV3;
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
        $disk = 'd_drive';
        Cache::forget($cacheKey);
        $courseData = Cache::remember($cacheKey, 120, function () use ($truefireCourse, $disk) {
            $course = $truefireCourse->load(['channels.segments' => function ($query) {
                $query->withVideo(); // Only load segments with valid video fields
            }]);
            
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
                    
                    // Use direct file system check instead of Storage facade (temporary fix)
                    $physicalPath = Storage::disk($disk)->path($newFilePath);
                    $isDownloaded = file_exists($physicalPath);
                    
                    try {
                        $segmentsWithSignedUrls[] = [
                            'id' => $segment->id,
                            'channel_id' => $channel->id,
                            'channel_name' => $channel->name ?? $channel->title ?? "Channel #{$channel->id}",
                            'video' => $segment->video,
                            'title' => $segment->title ?? "Segment #{$segment->id}",
                            'signed_url' => $segment->getSignedUrl(),
                            'is_downloaded' => $isDownloaded,
                            'file_size' => $isDownloaded ? Storage::disk($disk)->size($newFilePath) : null,
                            'downloaded_at' => $isDownloaded ? Storage::disk($disk)->lastModified($newFilePath) : null,
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
                            'file_size' => $isDownloaded ? Storage::disk($disk)->size($newFilePath) : null,
                            'downloaded_at' => $isDownloaded ? Storage::disk($disk)->lastModified($newFilePath) : null,
                        ];
                    }
                }
            }
            
            // Get the configured disk
            $disk = config('filesystems.default');
            
            // TEMPORARY DEBUG - Test storage methods in controller context
            Log::info("Controller Storage Test", [
                'disk_name' => $disk,
                'test_files_count' => count(Storage::disk($disk)->files($courseDir)),
                'test_file_exists' => Storage::disk($disk)->exists($courseDir . "/2860.mp4"),
                'first_3_files' => array_slice(Storage::disk($disk)->files($courseDir), 0, 3)
            ]);
            
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
            // Load segments with valid video fields for the course through channels
            $course = $truefireCourse->load(['channels.segments' => function ($query) {
                $query->withVideo(); // Only load segments with valid video fields
            }]);
            
            
            // Collect segments with valid video fields from all channels
            $allSegments = collect();
            foreach ($course->channels as $channel) {
                $allSegments = $allSegments->merge($channel->segments);
            }
            
            // Check if course has any segments with valid video fields
            if ($allSegments->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No segments with valid video fields found for this course.',
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
            $disk = 'd_drive';
            
            //check if directory exists
            if(!Storage::disk($disk)->exists($courseDir)){
                Storage::disk($disk)->makeDirectory($courseDir);
            }
            
            $stats = [
                'total_segments' => $segments->count(),
                'already_downloaded' => 0,
                'queued_downloads' => 0
            ];
            
            // Initialize download statistics cache
            $statsKey = "download_stats_{$truefireCourse->id}";
            $initialStats = ['success' => 0, 'failed' => 0, 'skipped' => 0];
            Cache::forget($statsKey);
            Cache::put($statsKey, $initialStats, 3600);
            
            Log::info('Starting background download jobs for TrueFire course', [
                'course_id' => $truefireCourse->id,
                'course_title' => $truefireCourse->title ?? "Course #{$truefireCourse->id}",
                'total_segments_with_video' => $stats['total_segments'],
                'test_mode' => $testMode,
                'storage_path' => Storage::disk($disk)->path($courseDir),
                'channels_count' => $course->channels->count(),
                'segments_with_video_count' => $allSegments->count(),
                'segments_to_process' => $segments->count(),
                'stats_cache_key' => $statsKey,
                'video_field_filtering' => 'enabled'
            ]);

            // Dispatch jobs for each segment
            foreach ($segments as $segment) {
                // Check for both new format (segmentId.mp4) and legacy format (segment-segmentId.mp4)
                $newFilename = "{$segment->id}.mp4";
                $legacyFilename = "segment-{$segment->id}.mp4";
                $newFilePath = "{$courseDir}/{$newFilename}";
                $legacyFilePath = "{$courseDir}/{$legacyFilename}";
                
                // Use direct file system check instead of Storage facade (temporary fix)
                $physicalPath = Storage::disk($disk)->path($newFilePath);
                $isDownloaded = Storage::disk($disk)->exists($newFilePath);
                
                // TEMPORARY: Try direct file system check
                $directExists = file_exists($physicalPath);
                if ($directExists && !$isDownloaded) {
                    Log::warning("Storage facade mismatch detected", [
                        'segment_id' => $segment->id,
                        'storage_exists' => $isDownloaded,
                        'direct_exists' => $directExists,
                        'path' => $newFilePath,
                        'physical_path' => $physicalPath
                    ]);
                    $isDownloaded = $directExists; // Use direct check as fallback
                }
                
                // Check if file already exists in either format
                if ($isDownloaded) {
                    $stats['already_downloaded']++;
                    $existingFilePath = $newFilePath;
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
                    // Track this segment as queued
                    $queuedKey = "queued_segments_{$truefireCourse->id}";
                    $queuedSegments = Cache::get($queuedKey, []);
                    $queuedSegments[] = $segment->id;
                    Cache::forget($queuedKey);
                    Cache::put($queuedKey, $queuedSegments, 3600); // Store for 1 hour
                    
                    // Dispatch background job with V3 implementation (generates signed URL at execution time)
                    
                    DownloadTruefireSegmentV3::dispatch($segment, $courseDir, $course->id, $segment->s3Path());
                    $stats['queued_downloads']++;
                    
                    Log::debug("Queued download job for segment", [
                        'segment_id' => $segment->id,
                        's3_path' => $s3Path,
                        'note' => 'Signed URL will be generated fresh at execution time',
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
                'storage_path' => Storage::disk($disk)->path($courseDir),
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
            // Load course with all segments (remove withVideo filter temporarily to debug)
            $course = $truefireCourse->load(['channels.segments']);
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            
            // Get the configured disk
            $disk = config('filesystems.default');
            
            // TEMPORARY DEBUG - Test storage methods in controller context
            Log::info("Controller Storage Test", [
                'disk_name' => $disk,
                'test_files_count' => count(Storage::disk($disk)->files($courseDir)),
                'test_file_exists' => Storage::disk($disk)->exists($courseDir . "/2860.mp4"),
                'first_3_files' => array_slice(Storage::disk($disk)->files($courseDir), 0, 3)
            ]);
            
            // Collect ALL segments from all channels first
            $allSegments = collect();
            foreach ($course->channels as $channel) {
                $allSegments = $allSegments->merge($channel->segments);
            }
            
            // Filter segments with valid video fields manually
            $segmentsWithVideo = $allSegments->filter(function ($segment) {
                return !empty($segment->video);
            });
            
            // TEMPORARY DEBUG - Show basic info
            Log::info("Download status debug", [
                'course_id' => $truefireCourse->id,
                'total_segments' => $allSegments->count(),
                'segments_with_video' => $segmentsWithVideo->count(),
                'first_5_segment_ids' => $segmentsWithVideo->take(5)->pluck('id')->toArray(),
                'looking_for_segment' => 2860,
                'segment_2860_exists' => $segmentsWithVideo->where('id', 2860)->isNotEmpty()
            ]);
            
            $status = [
                'course_id' => $truefireCourse->id,
                'total_segments' => $segmentsWithVideo->count(),
                'downloaded_segments' => 0,
                'storage_path' => Storage::disk($disk)->path($courseDir),
                'segments' => [],
                'debug_info' => [
                    'all_segments_count' => $allSegments->count(),
                    'segments_with_video_count' => $segmentsWithVideo->count(),
                    'channels_count' => $course->channels->count(),
                    'segment_2860_exists' => $segmentsWithVideo->where('id', 2860)->isNotEmpty(),
                    'first_5_segment_ids' => $segmentsWithVideo->take(5)->pluck('id')->toArray(),
                    'disk_used' => $disk,
                    'courseDir' => $courseDir
                ]
            ];

            // Log comprehensive debug info
            Log::info('Download status API called', [
                'course_id' => $truefireCourse->id,
                'all_segments' => $allSegments->count(),
                'segments_with_video' => $segmentsWithVideo->count(),
                'channels' => $course->channels->count(),
                'course_dir' => $courseDir,
                'storage_disk' => $disk,
                'storage_path' => Storage::disk($disk)->path($courseDir)
            ]);

            foreach ($segmentsWithVideo as $segment) {
                // Check for both new format (segmentId.mp4) and legacy format (segment-segmentId.mp4)
                $newFilename = "{$segment->id}.mp4";
                $legacyFilename = "segment-{$segment->id}.mp4";
                $newFilePath = "{$courseDir}/{$newFilename}";
                
                // Use direct file system check instead of Storage facade (temporary fix)
                $physicalPath = Storage::disk($disk)->path($newFilePath);
                $isDownloaded = file_exists($physicalPath);
                
                // TEMPORARY: Try direct file system check
                $directExists = file_exists($physicalPath);
                if ($directExists && !$isDownloaded) {
                    Log::warning("Storage facade mismatch detected", [
                        'segment_id' => $segment->id,
                        'storage_exists' => $isDownloaded,
                        'direct_exists' => $directExists,
                        'path' => $newFilePath,
                        'physical_path' => $physicalPath
                    ]);
                    $isDownloaded = $directExists; // Use direct check as fallback
                }
                
                // TEMPORARY DEBUG for segment 2860
                if ($segment->id == 2860) {
                    Log::info("DEBUG Segment 2860 - Final Result", [
                        'courseDir' => $courseDir,
                        'newFilePath' => $newFilePath,
                        'disk' => $disk,
                        'isDownloaded' => $isDownloaded,
                        'physical_path' => Storage::disk($disk)->path($newFilePath),
                        'files_in_dir' => Storage::disk($disk)->files($courseDir)
                    ]);
                }
                
                // Use the format that exists, prefer new format
                if ($isDownloaded) {
                    $status['downloaded_segments']++;
                }
                
                // Add debug logging for first few segments
                if (count($status['segments']) < 5) {
                    Log::info('Segment file check', [
                        'segment_id' => $segment->id,
                        'new_path' => $newFilePath,
                        'is_downloaded' => $isDownloaded,
                        'has_video_field' => !empty($segment->video)
                    ]);
                }
                
                $segmentData = [
                    'segment_id' => $segment->id,
                    'title' => $segment->title ?? "Segment #{$segment->id}",
                    'filename' => $newFilename,
                    'is_downloaded' => $isDownloaded,
                    'path' => $newFilePath,
                    'file_size' => null,
                    'downloaded_at' => null
                ];
                
                // Only get file info if downloaded to avoid errors
                if ($isDownloaded) {
                    try {
                        $segmentData['file_size'] = Storage::disk($disk)->size($newFilePath);
                        $segmentData['downloaded_at'] = Storage::disk($disk)->lastModified($newFilePath);
                    } catch (\Exception $e) {
                        Log::warning('Could not get file info for downloaded segment', [
                            'segment_id' => $segment->id,
                            'file_path' => $newFilePath,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                $status['segments'][] = $segmentData;
            }
            
            Log::info('Download status result', [
                'course_id' => $truefireCourse->id,
                'total_segments' => $status['total_segments'],
                'downloaded_segments' => $status['downloaded_segments'],
                'percentage' => $status['total_segments'] > 0 ? round(($status['downloaded_segments'] / $status['total_segments']) * 100, 1) : 0
            ]);

            return response()->json($status);

        } catch (\Exception $e) {
            Log::error('Error getting download status for TrueFire course', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
            // Get segments with valid video fields for this course
            $course = $truefireCourse->load(['channels.segments' => function ($query) {
                $query->withVideo(); // Only load segments with valid video fields
            }]);
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
                $disk = config('filesystems.default');
                $isDownloaded = Storage::disk($disk)->exists($filePath);
                
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
                    'file_size' => $isDownloaded ? Storage::disk($disk)->size($filePath) : null,
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
            // Load the course with segments (including video field filtering) to find the requested segment
            $course = $truefireCourse->load(['channels.segments' => function ($query) {
                $query->withVideo(); // Only load segments with valid video fields
            }]);
            
            // Find the specific segment (must have valid video field)
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
                    'message' => "Segment {$segmentId} not found in this course or does not have a valid video field."
                ], 404);
            }
            
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            
            // Ensure course directory exists
            $disk = config('filesystems.default');
            Storage::disk($disk)->makeDirectory($courseDir);
            
            // Check if file already exists
            $filename = "{$segment->id}.mp4";
            $filePath = "{$courseDir}/{$filename}";
            $isAlreadyDownloaded = Storage::disk($disk)->exists($filePath);
            
            Log::info('Starting individual segment download', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segment->id,
                'already_downloaded' => $isAlreadyDownloaded,
                'file_path' => $filePath
            ]);
            
            try {
                // Track this segment as queued
                $queuedKey = "queued_segments_{$truefireCourse->id}";
                $queuedSegments = Cache::get($queuedKey, []);
                $queuedSegments[] = $segment->id;
                $s3Path = str_replace('mp4:','', $segment->video);
                $s3Path = explode('/', $s3Path)[0];
                $s3Path = "{$s3Path}/{$segment->id}_med.mp4";
                Cache::forget($queuedKey);
                Cache::put($queuedKey, $queuedSegments, 3600); // Store for 1 hour
                
                // Dispatch background job with V3 implementation (generates signed URL at execution time)
                DownloadTruefireSegmentV3::dispatch($segment, $courseDir, $course->id, $s3Path);
                
                Log::info("Queued download job for individual segment", [
                    'segment_id' => $segment->id,
                    'course_id' => $truefireCourse->id,
                    'note' => 'Signed URL will be generated fresh at execution time'
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
                    'storage_path' => Storage::disk($disk)->path($courseDir),
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

    /**
     * Download all videos from ALL TrueFire courses to local storage.
     * Uses Laravel queues for background processing with course-level batching.
     */
    public function downloadAllCourses(Request $request)
    {
        try {
            // Get all TrueFire courses
            $courses = TruefireCourse::withCount(['channels', 'segments' => function ($query) {
                $query->withVideo(); // Only count segments with valid video fields
            }])->get();

            if ($courses->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No TrueFire courses found.',
                    'stats' => [
                        'total_courses' => 0,
                        'total_segments' => 0,
                        'courses_queued' => 0
                    ]
                ], 404);
            }

            // Check if this is a test mode (limit to 1 course for faster testing)
            $testMode = $request->get('test', false);
            $coursesToProcess = $testMode ? $courses->take(1) : $courses;

            $bulkStats = [
                'total_courses' => $coursesToProcess->count(),
                'total_segments' => 0,
                'courses_queued' => 0,
                'courses_with_no_segments' => 0
            ];

            // Initialize bulk download statistics cache
            $bulkStatsKey = "bulk_download_stats";
            $initialBulkStats = [
                'courses_processed' => 0,
                'courses_completed' => 0,
                'courses_failed' => 0,
                'total_segments_success' => 0,
                'total_segments_failed' => 0,
                'total_segments_skipped' => 0
            ];
            Cache::put($bulkStatsKey, $initialBulkStats, 7200); // Store for 2 hours

            // Track which courses are being processed
            $processingCoursesKey = "bulk_processing_courses";
            $queuedCoursesKey = "bulk_queued_courses";
            Cache::put($processingCoursesKey, [], 7200);
            Cache::put($queuedCoursesKey, $coursesToProcess->pluck('id')->toArray(), 7200);

            Log::info('Starting bulk download for all TrueFire courses', [
                'total_courses' => $bulkStats['total_courses'],
                'test_mode' => $testMode,
                'bulk_stats_cache_key' => $bulkStatsKey,
                'processing_courses_key' => $processingCoursesKey,
                'queued_courses_key' => $queuedCoursesKey
            ]);

            // Process each course
            foreach ($coursesToProcess as $course) {
                // Load segments with valid video fields for the course through channels
                $courseWithSegments = $course->load(['channels.segments' => function ($query) {
                    $query->withVideo(); // Only load segments with valid video fields
                }]);

                // Collect segments with valid video fields from all channels
                $allSegments = collect();
                foreach ($courseWithSegments->channels as $channel) {
                    $allSegments = $allSegments->merge($channel->segments);
                }

                // Skip courses with no valid segments
                if ($allSegments->isEmpty()) {
                    $bulkStats['courses_with_no_segments']++;
                    Log::debug("Skipping course with no valid segments", [
                        'course_id' => $course->id,
                        'course_title' => $course->title ?? "Course #{$course->id}"
                    ]);
                    continue;
                }

                $bulkStats['total_segments'] += $allSegments->count();

                // Initialize individual course download statistics
                $courseStatsKey = "download_stats_{$course->id}";
                $initialCourseStats = ['success' => 0, 'failed' => 0, 'skipped' => 0];
                Cache::put($courseStatsKey, $initialCourseStats, 3600);

                $courseDir = "truefire-courses/{$course->id}";
                
                // Ensure course directory exists
                $disk = config('filesystems.default');
                Storage::disk($disk)->makeDirectory($courseDir);

                $courseSegmentsQueued = 0;
                $courseSegmentsSkipped = 0;

                // Dispatch jobs for each segment in this course
                foreach ($allSegments as $segment) {
                    // Check for both new format (segmentId.mp4) and legacy format (segment-segmentId.mp4)
                    $newFilename = "{$segment->id}.mp4";
                    $legacyFilename = "segment-{$segment->id}.mp4";
                    $newFilePath = "{$courseDir}/{$newFilename}";
                    $legacyFilePath = "{$courseDir}/{$legacyFilename}";
                    
                    // Use direct file system check instead of Storage facade (temporary fix)
                    $physicalPath = Storage::disk($disk)->path($newFilePath);
                    $isDownloaded = file_exists($physicalPath);
                    
                    // Check if file already exists in either format
                    if ($isDownloaded) {
                        $courseSegmentsSkipped++;
                        $existingFilePath = $newFilePath;
                        Log::debug("Segment already downloaded, skipping job", [
                            'course_id' => $course->id,
                            'segment_id' => $segment->id,
                            'file_path' => $existingFilePath
                        ]);
                        continue;
                    }

                    try {
                        // Track this segment as queued for the course
                        $queuedKey = "queued_segments_{$course->id}";
                        $queuedSegments = Cache::get($queuedKey, []);
                        $queuedSegments[] = $segment->id;
                        Cache::put($queuedKey, $queuedSegments, 3600); // Store for 1 hour
                        
                        // Dispatch background job with V3 implementation (generates signed URL at execution time)
                        
                        DownloadTruefireSegmentV3::dispatch($segment, $courseDir, $course->id, $segment->s3Path());
                        $courseSegmentsQueued++;
                        
                    } catch (\Exception $e) {
                        Log::error('Failed to queue download job for segment', [
                            'course_id' => $course->id,
                            'segment_id' => $segment->id,
                            'error' => $e->getMessage()
                        ]);
                        DownloadTruefireSegmentV3::dispatch($segment, $courseDir, $course->id, $segment->s3Path());
                        $courseSegmentsQueued++;
                        
                        Log::debug("Queued download job for segment in bulk operation", [
                            'course_id' => $course->id,
                            'segment_id' => $segment->id,
                            'note' => 'Signed URL will be generated fresh at execution time',
                            'queued_segments_count' => count($queuedSegments)
                        ]);
                        
                    } catch (\Exception $e) {
                        Log::error('Failed to queue download job for segment in bulk operation', [
                            'course_id' => $course->id,
                            'segment_id' => $segment->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                if ($courseSegmentsQueued > 0) {
                    $bulkStats['courses_queued']++;
                    Log::info("Queued course for bulk download", [
                        'course_id' => $course->id,
                        'course_title' => $course->title ?? "Course #{$course->id}",
                        'segments_queued' => $courseSegmentsQueued,
                        'segments_skipped' => $courseSegmentsSkipped
                    ]);
                }

                // Clear caches related to this course
                $this->clearCourseCache($course->id);
            }

            // Log final results
            Log::info('Completed bulk download queue setup for all TrueFire courses', [
                'bulk_stats' => $bulkStats
            ]);

            $message = "Bulk download jobs queued: {$bulkStats['courses_queued']} courses with " .
                      "{$bulkStats['total_segments']} total segments queued for download. " .
                      "Downloads will continue in the background.";

            if ($bulkStats['courses_with_no_segments'] > 0) {
                $message .= " {$bulkStats['courses_with_no_segments']} courses were skipped (no valid segments).";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'stats' => $bulkStats,
                'background_processing' => true,
                'cache_keys' => [
                    'bulk_stats' => $bulkStatsKey,
                    'processing_courses' => $processingCoursesKey,
                    'queued_courses' => $queuedCoursesKey
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error queuing bulk download jobs for all TrueFire courses', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while queuing bulk download jobs.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'stats' => [
                    'total_courses' => 0,
                    'total_segments' => 0,
                    'courses_queued' => 0
                ]
            ], 500);
        }
    }

    /**
     * Get bulk download status - overall progress across all courses
     */
    public function bulkDownloadStatus()
    {
        try {
            // Get bulk download statistics from cache
            $bulkStatsKey = "bulk_download_stats";
            $bulkStats = Cache::get($bulkStatsKey, [
                'courses_processed' => 0,
                'courses_completed' => 0,
                'courses_failed' => 0,
                'total_segments_success' => 0,
                'total_segments_failed' => 0,
                'total_segments_skipped' => 0
            ]);

            // Get processing and queued courses
            $processingCoursesKey = "bulk_processing_courses";
            $queuedCoursesKey = "bulk_queued_courses";
            $processingCourses = Cache::get($processingCoursesKey, []);
            $queuedCourses = Cache::get($queuedCoursesKey, []);

            // Get individual course statistics
            $courseDetails = [];
            $allCourseIds = array_unique(array_merge($processingCourses, $queuedCourses));
            
            foreach ($allCourseIds as $courseId) {
                $courseStatsKey = "download_stats_{$courseId}";
                $courseStats = Cache::get($courseStatsKey, ['success' => 0, 'failed' => 0, 'skipped' => 0]);
                
                // Get course info
                $course = TruefireCourse::find($courseId);
                if ($course) {
                    $courseDetails[] = [
                        'course_id' => $courseId,
                        'course_title' => $course->title ?? "Course #{$courseId}",
                        'stats' => $courseStats,
                        'is_processing' => in_array($courseId, $processingCourses),
                        'is_queued' => in_array($courseId, $queuedCourses)
                    ];
                }
            }

            $status = [
                'bulk_stats' => $bulkStats,
                'processing_courses_count' => count($processingCourses),
                'queued_courses_count' => count($queuedCourses),
                'course_details' => $courseDetails,
                'cache_keys' => [
                    'bulk_stats' => $bulkStatsKey,
                    'processing_courses' => $processingCoursesKey,
                    'queued_courses' => $queuedCoursesKey
                ]
            ];

            return response()->json($status);

        } catch (\Exception $e) {
            Log::error('Error getting bulk download status', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error getting bulk download status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get bulk queue status - monitor queue status for bulk operation
     */
    public function bulkQueueStatus()
    {
        try {
            // Get queued courses
            $queuedCoursesKey = "bulk_queued_courses";
            $queuedCourses = Cache::get($queuedCoursesKey, []);

            if (empty($queuedCourses)) {
                return response()->json([
                    'message' => 'No bulk download operation in progress',
                    'queued_courses' => [],
                    'total_queued_jobs' => 0,
                    'total_failed_jobs' => 0
                ]);
            }

            $courseQueueStatuses = [];
            $totalQueuedJobs = 0;
            $totalFailedJobs = 0;

            // Get queue status for each course
            foreach ($queuedCourses as $courseId) {
                $course = TruefireCourse::find($courseId);
                if (!$course) continue;

                // Get segments with valid video fields for this course
                $courseWithSegments = $course->load(['channels.segments' => function ($query) {
                    $query->withVideo(); // Only load segments with valid video fields
                }]);
                
                $allSegments = collect();
                foreach ($courseWithSegments->channels as $channel) {
                    $allSegments = $allSegments->merge($channel->segments);
                }

                $segmentIds = $allSegments->pluck('id')->toArray();
                $courseDir = "truefire-courses/{$courseId}";

                // Count queued and failed jobs for this course
                $queuedJobsCount = 0;
                $failedJobsCount = 0;

                if (config('queue.default') === 'database') {
                    // Check queued jobs
                    $queuedJobs = \DB::table('jobs')
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
                    $queuedJobsCount = $queuedJobs->count();

                    // Check failed jobs
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
                    $failedJobsCount = $failedJobs->count();
                }

                $totalQueuedJobs += $queuedJobsCount;
                $totalFailedJobs += $failedJobsCount;

                $courseQueueStatuses[] = [
                    'course_id' => $courseId,
                    'course_title' => $course->title ?? "Course #{$courseId}",
                    'total_segments' => count($segmentIds),
                    'queued_jobs' => $queuedJobsCount,
                    'failed_jobs' => $failedJobsCount
                ];
            }

            return response()->json([
                'queued_courses' => $courseQueueStatuses,
                'total_queued_jobs' => $totalQueuedJobs,
                'total_failed_jobs' => $totalFailedJobs,
                'queue_driver' => config('queue.default'),
                'using_database_queue' => config('queue.default') === 'database'
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting bulk queue status', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error getting bulk queue status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get bulk download statistics - real-time statistics for all courses combined
     */
    public function bulkDownloadStats()
    {
        try {
            // Get bulk download statistics from cache
            $bulkStatsKey = "bulk_download_stats";
            $bulkStats = Cache::get($bulkStatsKey, [
                'courses_processed' => 0,
                'courses_completed' => 0,
                'courses_failed' => 0,
                'total_segments_success' => 0,
                'total_segments_failed' => 0,
                'total_segments_skipped' => 0
            ]);

            // Get queued courses to calculate real-time aggregated stats
            $queuedCoursesKey = "bulk_queued_courses";
            $queuedCourses = Cache::get($queuedCoursesKey, []);

            // Aggregate individual course stats
            $aggregatedStats = [
                'success' => 0,
                'failed' => 0,
                'skipped' => 0
            ];

            foreach ($queuedCourses as $courseId) {
                $courseStatsKey = "download_stats_{$courseId}";
                $courseStats = Cache::get($courseStatsKey, ['success' => 0, 'failed' => 0, 'skipped' => 0]);
                
                $aggregatedStats['success'] += $courseStats['success'];
                $aggregatedStats['failed'] += $courseStats['failed'];
                $aggregatedStats['skipped'] += $courseStats['skipped'];
            }

            // Combine bulk stats with aggregated real-time stats
            $combinedStats = array_merge($bulkStats, [
                'real_time_aggregated' => $aggregatedStats,
                'active_courses_count' => count($queuedCourses)
            ]);

            Log::debug("Retrieved bulk download stats", [
                'bulk_cache_key' => $bulkStatsKey,
                'queued_courses_key' => $queuedCoursesKey,
                'bulk_stats' => $bulkStats,
                'aggregated_stats' => $aggregatedStats,
                'active_courses' => count($queuedCourses)
            ]);

            return response()->json($combinedStats);

        } catch (\Exception $e) {
            Log::error('Error getting bulk download stats', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'courses_processed' => 0,
                'courses_completed' => 0,
                'courses_failed' => 0,
                'total_segments_success' => 0,
                'total_segments_failed' => 0,
                'total_segments_skipped' => 0,
                'real_time_aggregated' => [
                    'success' => 0,
                    'failed' => 0,
                    'skipped' => 0
                ],
                'active_courses_count' => 0,
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}