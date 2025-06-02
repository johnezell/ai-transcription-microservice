<?php

namespace App\Http\Controllers;

use App\Models\TruefireCourse;
use App\Jobs\DownloadTruefireSegment;
use App\Jobs\DownloadTruefireSegmentV2;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use GuzzleHttp\Client as GuzzleClient;

class TruefireCourseController extends Controller
{
    /**
     * Display a listing of TrueFire courses.
     */
    public function index(Request $request)
    {
        $search = $request->get('search', '');
        $perPage = 15; // Items per page
        
        // Create cache key based on search and page parameters
        $cacheKey = 'truefire_courses_index_' . md5($search . '_' . ($request->get('page', 1)) . '_' . $perPage);
        
        // Cache the results for 5 minutes with tags if supported
        $courses = $this->cacheWithTagsSupport(
            ['truefire_courses_index'], 
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
        // Cache the course data for 2 minutes (shorter than index because download status changes)
        $cacheKey = 'truefire_course_show_' . $truefireCourse->id;
        
        $courseData = Cache::remember($cacheKey, 120, function () use ($truefireCourse) {
            $course = $truefireCourse->load(['channels.segments']);
            
            // Set up course directory path for checking downloaded files
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            
            // Generate signed URLs for all segments and include download status
            $segmentsWithSignedUrls = [];
            foreach ($course->channels as $channel) {
                foreach ($channel->segments as $segment) {
                    // Check if this segment is already downloaded
                    $filename = "{$segment->id}.mp4";
                    $filePath = "{$courseDir}/{$filename}";
                    $isDownloaded = Storage::disk('local')->exists($filePath);
                    
                    try {
                        $segmentsWithSignedUrls[] = [
                            'id' => $segment->id,
                            'channel_id' => $channel->id,
                            'channel_name' => $channel->name ?? $channel->title ?? "Channel #{$channel->id}",
                            'video' => $segment->video,
                            'title' => $segment->title ?? "Segment #{$segment->id}",
                            'signed_url' => $segment->getSignedUrl(),
                            'is_downloaded' => $isDownloaded,
                            'file_size' => $isDownloaded ? Storage::disk('local')->size($filePath) : null,
                            'downloaded_at' => $isDownloaded ? Storage::disk('local')->lastModified($filePath) : null,
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
                            'file_size' => $isDownloaded ? Storage::disk('local')->size($filePath) : null,
                            'downloaded_at' => $isDownloaded ? Storage::disk('local')->lastModified($filePath) : null,
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
            // Load all segments for the course
            $course = $truefireCourse->load(['segments']);
            
            // Check if course has any segments
            if ($course->segments->isEmpty()) {
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
            $segments = $testMode ? $course->segments->take(1) : $course->segments;

            $courseDir = "truefire-courses/{$truefireCourse->id}";
            
            // Ensure course directory exists
            Storage::disk('local')->makeDirectory($courseDir);

            $stats = [
                'total_segments' => $segments->count(),
                'already_downloaded' => 0,
                'queued_downloads' => 0
            ];

            Log::info('Starting background download jobs for TrueFire course', [
                'course_id' => $truefireCourse->id,
                'course_title' => $truefireCourse->title ?? "Course #{$truefireCourse->id}",
                'total_segments' => $stats['total_segments'],
                'test_mode' => $testMode,
                'storage_path' => storage_path("app/{$courseDir}")
            ]);

            // Dispatch jobs for each segment
            foreach ($segments as $segment) {
                $filename = "{$segment->id}.mp4";
                $filePath = "{$courseDir}/{$filename}";
                
                // Check if file already exists
                if (Storage::disk('local')->exists($filePath)) {
                    $stats['already_downloaded']++;
                    Log::debug("Segment already downloaded, skipping job", [
                        'segment_id' => $segment->id,
                        'file_path' => $filePath
                    ]);
                    continue;
                }

                try {
                    // Generate signed URL
                    $signedUrl = $segment->getSignedUrl();
                    
                    // Dispatch background job with improved V2 implementation
                    DownloadTruefireSegmentV2::dispatch($segment, $courseDir, $signedUrl, $course->id);
                    $stats['queued_downloads']++;
                    
                    Log::debug("Queued download job for segment", [
                        'segment_id' => $segment->id,
                        'signed_url' => substr($signedUrl, 0, 100) . '...'
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
            // Cache download status for 1 minute (short cache since download status can change frequently)
            $cacheKey = 'truefire_download_status_' . $truefireCourse->id;
            
            $status = Cache::remember($cacheKey, 60, function () use ($truefireCourse) {
                $course = $truefireCourse->load(['segments']);
                $courseDir = "truefire-courses/{$truefireCourse->id}";
                
                $status = [
                    'course_id' => $truefireCourse->id,
                    'total_segments' => $course->segments->count(),
                    'downloaded_segments' => 0,
                    'storage_path' => storage_path("app/{$courseDir}"),
                    'segments' => []
                ];

                foreach ($course->segments as $segment) {
                    $filename = "{$segment->id}.mp4";
                    $filePath = "{$courseDir}/{$filename}";
                    $isDownloaded = Storage::disk('local')->exists($filePath);
                    
                    if ($isDownloaded) {
                        $status['downloaded_segments']++;
                    }
                    
                    $status['segments'][] = [
                        'segment_id' => $segment->id,
                        'title' => $segment->title ?? "Segment #{$segment->id}",
                        'filename' => $filename,
                        'is_downloaded' => $isDownloaded,
                        'file_size' => $isDownloaded ? Storage::disk('local')->size($filePath) : null,
                        'downloaded_at' => $isDownloaded ? Storage::disk('local')->lastModified($filePath) : null
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
            
            Log::debug("Retrieved download stats for course {$truefireCourse->id}", $stats);
            
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
        // Clear course show cache
        Cache::forget('truefire_course_show_' . $courseId);
        
        // Clear download status cache
        Cache::forget('truefire_download_status_' . $courseId);
        
        // Clear index caches - try tags first, fallback to individual keys
        $this->clearIndexCaches();
        
        Log::debug('Cleared caches for course', ['course_id' => $courseId]);
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
            // Try clearing with tags first
            Cache::tags(['truefire_courses_index'])->flush();
        } catch (\Exception $e) {
            // Fallback: manually clear known cache keys
            Log::debug('Cache tag flush not supported, clearing individual keys', [
                'error' => $e->getMessage()
            ]);
            
            // Clear common cache patterns manually
            $patterns = [
                'truefire_courses_index_', // Base pattern
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
}