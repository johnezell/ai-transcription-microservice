<?php

namespace App\Http\Controllers;

use App\Models\LocalTruefireCourse;
use App\Models\LocalTruefireSegment;
use App\Models\TruefireSegmentProcessing;
use App\Models\CourseAudioPreset;
use App\Models\CourseTranscriptionPreset;
use App\Models\SegmentDownload;
use App\Models\TranscriptionLog;
use App\Models\AudioTestBatch;
use App\Jobs\DownloadTruefireSegmentV3;
use App\Jobs\AudioExtractionTestJob;
use App\Jobs\BatchAudioExtractionJob;
use App\Jobs\TruefireSegmentAudioExtractionJob;
use App\Jobs\TruefireSegmentTranscriptionJob;
use App\Jobs\TruefireSegmentTerminologyJob;
use App\Http\Requests\CreateBatchTestRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
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
                $query = LocalTruefireCourse::query();
                
                // Apply search filter if search term is provided
                if (!empty($search)) {
                    $query->where(function ($q) use ($search) {
                        $q->where('id', 'like', '%' . $search . '%')
                          ->orWhere('title', 'like', '%' . $search . '%');
                    });
                }
                
                // Load relationships and counts for local models
                $query->withCount([
                    'channels',
                    'segments' => function ($query) {
                        $query->withVideo(); // Only count segments with valid video fields
                    }
                ])->withSum(['segments' => function ($query) {
                    $query->withVideo(); // Only sum runtime for segments with valid video fields
                }], 'runtime');
                
                $courses = $query->paginate($perPage);
                
                // Transform courses to include proper preset data
                $courses->getCollection()->transform(function ($course) {
                    // Add the correct audio extraction preset using the model method
                    $course->audio_extraction_preset = $course->getAudioExtractionPreset();
                    return $course;
                });
                
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
    public function show(LocalTruefireCourse $truefireCourse)
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
                    
                    // Check if S3 signed URL generation is enabled
                    $s3Enabled = config('app.truefire_s3_enabled', false);
                    $signedUrl = null;
                    $urlError = null;
                    
                    if ($s3Enabled) {
                        try {
                            $signedUrl = $segment->getSignedUrl();
                        } catch (\Exception $e) {
                            \Log::warning('Failed to generate signed URL for segment', [
                                'segment_id' => $segment->id,
                                'error' => $e->getMessage()
                            ]);
                            $urlError = 'Failed to generate signed URL';
                        }
                    } else {
                        // S3 signed URLs disabled - assets are localized
                        $signedUrl = null;
                    }

                    $segmentData = [
                        'id' => $segment->id,
                        'channel_id' => $channel->id,
                        'channel_name' => $channel->name ?? $channel->title ?? "Channel #{$channel->id}",
                        'video' => $segment->video,
                        'title' => $segment->title ?? "Segment #{$segment->id}",
                        'signed_url' => $signedUrl,
                        'is_downloaded' => $isDownloaded,
                        'file_size' => $isDownloaded ? Storage::disk($disk)->size($newFilePath) : null,
                        'downloaded_at' => $isDownloaded ? Storage::disk($disk)->lastModified($newFilePath) : null,
                    ];
                    
                    if ($urlError) {
                        $segmentData['error'] = $urlError;
                    }
                    
                    $segmentsWithSignedUrls[] = $segmentData;
                }
            }
            
            // Get the configured disk
            $disk = 'd_drive'; // Always use d_drive for TrueFire courses
            
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
    public function downloadAll(LocalTruefireCourse $truefireCourse, Request $request)
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
                // Check database status first to prevent duplicate jobs
                if (SegmentDownload::isAlreadyProcessed($segment->id)) {
                    $stats['already_downloaded']++;
                    Log::debug("Segment already processed or being processed in database, skipping job", [
                        'segment_id' => $segment->id,
                        'course_id' => $truefireCourse->id
                    ]);
                    continue;
                }

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
                    
                    // Mark as completed in database if not already tracked
                    SegmentDownload::createOrUpdate(
                        $segment->id,
                        $truefireCourse->id,
                        SegmentDownload::STATUS_COMPLETED
                    );
                    continue;
                }
                
                // Use new filename format for new downloads
                $filename = $newFilename;
                $filePath = $newFilePath;

                try {
                    // Create database entry to mark as queued
                    SegmentDownload::createOrUpdate(
                        $segment->id,
                        $truefireCourse->id,
                        SegmentDownload::STATUS_QUEUED
                    );

                    // Track this segment as queued (legacy cache support)
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
                        's3_path' => $segment->s3Path(),
                        'note' => 'Signed URL will be generated fresh at execution time',
                        'queued_segments_count' => count($queuedSegments),
                        'database_tracked' => true
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
    public function downloadStatus(LocalTruefireCourse $truefireCourse)
    {
        try {
            // Load course with all segments (remove withVideo filter temporarily to debug)
            $course = $truefireCourse->load(['channels.segments']);
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            
            // Get the configured disk
            $disk = 'd_drive'; // Always use d_drive for TrueFire courses
            
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
    public function downloadStats(LocalTruefireCourse $truefireCourse)
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
                    $query = LocalTruefireCourse::withCount('segments')
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
    public function queueStatus(LocalTruefireCourse $truefireCourse)
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
                
                // Try multiple possible queue names - prioritize default queue
                $possibleQueues = ['default', null, '', 'downloads'];
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
                $disk = 'd_drive'; // Always use d_drive for TrueFire courses
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
    public function downloadSegment(LocalTruefireCourse $truefireCourse, $segmentId)
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
            $disk = 'd_drive'; // Always use d_drive for TrueFire courses
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
                // Check database status first to prevent duplicate jobs
                if (SegmentDownload::isAlreadyProcessed($segment->id)) {
                    Log::info("Segment already processed or being processed in database, skipping job", [
                        'segment_id' => $segment->id,
                        'course_id' => $truefireCourse->id
                    ]);
                    
                    return response()->json([
                        'success' => true,
                        'message' => "Segment {$segment->id} is already processed or being processed.",
                        'segment' => [
                            'id' => $segment->id,
                            'title' => $segment->title ?? "Segment #{$segment->id}",
                            'filename' => $filename,
                            'already_processed' => true
                        ],
                        'background_processing' => false
                    ]);
                }

                // Create database entry to mark as queued
                SegmentDownload::createOrUpdate(
                    $segment->id,
                    $truefireCourse->id,
                    SegmentDownload::STATUS_QUEUED
                );

                // Track this segment as queued (legacy cache support)
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
                    'note' => 'Signed URL will be generated fresh at execution time',
                    'database_tracked' => true
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
            $courses = LocalTruefireCourse::withCount(['channels', 'segments' => function ($query) {
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
                $disk = 'd_drive'; // Always use d_drive for TrueFire courses
                Storage::disk($disk)->makeDirectory($courseDir);

                $courseSegmentsQueued = 0;
                $courseSegmentsSkipped = 0;

                // Dispatch jobs for each segment in this course
                foreach ($allSegments as $segment) {
                    // Check database status first to prevent duplicate jobs
                    if (SegmentDownload::isAlreadyProcessed($segment->id)) {
                        $courseSegmentsSkipped++;
                        Log::debug("Segment already processed or being processed in database, skipping job", [
                            'course_id' => $course->id,
                            'segment_id' => $segment->id
                        ]);
                        continue;
                    }

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
                        
                        // Mark as completed in database if not already tracked
                        SegmentDownload::createOrUpdate(
                            $segment->id,
                            $course->id,
                            SegmentDownload::STATUS_COMPLETED
                        );
                        continue;
                    }

                    try {
                        // Create database entry to mark as queued
                        SegmentDownload::createOrUpdate(
                            $segment->id,
                            $course->id,
                            SegmentDownload::STATUS_QUEUED
                        );

                        // Track this segment as queued for the course (legacy cache support)
                        $queuedKey = "queued_segments_{$course->id}";
                        $queuedSegments = Cache::get($queuedKey, []);
                        $queuedSegments[] = $segment->id;
                        Cache::put($queuedKey, $queuedSegments, 3600); // Store for 1 hour
                        
                        // Dispatch background job with V3 implementation (generates signed URL at execution time)
                        DownloadTruefireSegmentV3::dispatch($segment, $courseDir, $course->id, $segment->s3Path());
                        $courseSegmentsQueued++;
                        
                        Log::debug("Queued download job for segment in bulk operation", [
                            'course_id' => $course->id,
                            'segment_id' => $segment->id,
                            'note' => 'Signed URL will be generated fresh at execution time',
                            'queued_segments_count' => count($queuedSegments),
                            'database_tracked' => true
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
                $course = LocalTruefireCourse::find($courseId);
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
                $course = LocalTruefireCourse::find($courseId);
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

    /**
     * Test audio extraction for a specific segment
     */
    public function testAudioExtraction(LocalTruefireCourse $truefireCourse, $segmentId, Request $request)
    {
        try {
            // Validate the request with support for both single and multi-quality
            $validated = $request->validate([
                'quality_level' => 'sometimes|string|in:fast,balanced,high,premium',
                'quality_levels' => 'sometimes|array|min:1',
                'quality_levels.*' => 'string|in:fast,balanced,high,premium',
                'is_multi_quality' => 'sometimes|boolean',
                'enable_quality_analysis' => 'sometimes|boolean',
                'extraction_settings' => 'sometimes|array',
                'extraction_settings.sample_rate' => 'sometimes|integer|in:22050,44100,48000',
                'extraction_settings.bit_rate' => 'sometimes|string|in:128k,192k,256k,320k',
                'extraction_settings.channels' => 'sometimes|integer|in:1,2',
                'extraction_settings.format' => 'sometimes|string|in:mp3,wav,flac'
            ]);

            // Load the course with segments to find the requested segment
            $course = $truefireCourse->load(['channels.segments' => function ($query) {
                $query->withVideo(); // Only load segments with valid video fields
            }]);

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
                    'message' => "Segment {$segmentId} not found in this course or does not have a valid video field."
                ], 404);
            }

            // Check if segment video file exists locally
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            $videoFilename = "{$segment->id}.mp4";
            $videoFilePath = "{$courseDir}/{$videoFilename}";
            $disk = 'd_drive'; // Always use d_drive for TrueFire courses
            
            if (!Storage::disk($disk)->exists($videoFilePath)) {
                return response()->json([
                    'success' => false,
                    'message' => "Video file for segment {$segmentId} not found. Please download the video first.",
                    'required_file' => $videoFilePath
                ], 404);
            }

            // Determine quality levels to test
            $qualityLevels = [];
            $isMultiQuality = $validated['is_multi_quality'] ?? false;
            
            if ($isMultiQuality && isset($validated['quality_levels'])) {
                $qualityLevels = array_unique($validated['quality_levels']);
            } elseif (isset($validated['quality_level'])) {
                $qualityLevels = [$validated['quality_level']];
            } else {
                // Default to balanced quality
                $qualityLevels = ['balanced'];
            }

            // Set default extraction settings
            $extractionSettings = $validated['extraction_settings'] ?? [
                'sample_rate' => 44100,
                'bit_rate' => '192k',
                'channels' => 2,
                'format' => 'wav'
            ];

            // Add quality analysis setting
            $enableQualityAnalysis = $validated['enable_quality_analysis'] ?? false;

            // Dispatch jobs for each quality level
            $dispatchedJobs = [];
            $baseJobId = 'audio_extract_test_' . $truefireCourse->id . '_' . $segmentId . '_' . time();

            foreach ($qualityLevels as $index => $qualityLevel) {
                // Create a unique job ID for each quality level
                $audioExtractionJobId = $baseJobId . '_' . $qualityLevel . '_' . uniqid();

                Log::info('TruefireCourseController dispatching AudioExtractionTestJob', [
                    'course_id' => $truefireCourse->id,
                    'segment_id' => $segmentId,
                    'video_file_path' => $videoFilePath,
                    'video_filename' => $videoFilename,
                    'quality_level' => $qualityLevel,
                    'extraction_settings' => $extractionSettings,
                    'job_id' => $audioExtractionJobId,
                    'is_multi_quality' => $isMultiQuality,
                    'quality_index' => $index + 1,
                    'total_qualities' => count($qualityLevels),
                    'workflow_step' => 'controller_job_dispatch'
                ]);

                // Dispatch audio extraction test job with file paths (no Video model needed)
                AudioExtractionTestJob::dispatch(
                    $videoFilePath,
                    $videoFilename,
                    $qualityLevel,
                    array_merge($extractionSettings, [
                        'is_multi_quality' => $isMultiQuality,
                        'quality_index' => $index + 1,
                        'total_qualities' => count($qualityLevels),
                        'multi_quality_group_id' => $baseJobId,
                        'enable_quality_analysis' => $enableQualityAnalysis
                    ]),
                    $segmentId,
                    $truefireCourse->id,
                    $audioExtractionJobId // Pass the job ID that frontend will poll for
                );

                $dispatchedJobs[] = [
                    'job_id' => $audioExtractionJobId,
                    'quality_level' => $qualityLevel,
                    'index' => $index + 1
                ];

                Log::info('Audio extraction test job queued successfully', [
                    'course_id' => $truefireCourse->id,
                    'segment_id' => $segmentId,
                    'quality_level' => $qualityLevel,
                    'extraction_settings' => $extractionSettings,
                    'video_file_path' => $videoFilePath,
                    'job_id' => $audioExtractionJobId,
                    'workflow_step' => 'controller_job_queued_success'
                ]);
            }

            Log::info('All audio extraction test jobs dispatched', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'total_jobs' => count($dispatchedJobs),
                'quality_levels' => $qualityLevels,
                'is_multi_quality' => $isMultiQuality,
                'workflow_step' => 'all_jobs_dispatched'
            ]);

            return response()->json([
                'success' => true,
                'message' => $isMultiQuality 
                    ? "Multi-quality audio extraction tests queued for segment {$segmentId} (" . count($qualityLevels) . " quality levels)"
                    : "Audio extraction test queued for segment {$segmentId}",
                'jobs' => $dispatchedJobs,
                'segment' => [
                    'id' => $segment->id,
                    'title' => $segment->title ?? "Segment #{$segment->id}",
                    'video_file' => $videoFilename
                ],
                'test_parameters' => [
                    'quality_levels' => $qualityLevels,
                    'is_multi_quality' => $isMultiQuality,
                    'extraction_settings' => $extractionSettings,
                    'total_jobs' => count($dispatchedJobs)
                ],
                'background_processing' => true,
                'workflow_info' => [
                    'next_step' => 'Jobs will be processed by queue worker',
                    'expected_logs' => 'Check Laravel logs for workflow_step progress',
                    'multi_quality_group_id' => $baseJobId
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error queuing audio extraction test', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while queuing the audio extraction test.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get audio test results for a specific segment
     */
    public function getAudioTestResults(LocalTruefireCourse $truefireCourse, $segmentId, Request $request)
    {
        try {
            // Get test ID and quality level from request if provided
            $testId = $request->get('test_id');
            $qualityLevel = $request->get('quality_level');
            $multiQualityGroupId = $request->get('multi_quality_group_id');

            // Build query for transcription logs
            $query = TranscriptionLog::where('is_test_extraction', true);

            if ($testId) {
                // Get specific test result by job_id (which might contain the test_id)
                $query->where(function($q) use ($testId) {
                    $q->where('id', $testId)
                      ->orWhere('job_id', 'like', "%{$testId}%");
                });
            } else {
                // Get test results for this segment - search by file path pattern
                $courseDir = "truefire-courses/{$truefireCourse->id}";
                $query->where('file_path', 'like', "%{$courseDir}/{$segmentId}.mp4%");
                
                // Filter by quality level if specified
                if ($qualityLevel) {
                    $query->where('test_quality_level', $qualityLevel);
                }

                // Filter by multi-quality group if specified
                if ($multiQualityGroupId) {
                    $query->where('job_id', 'like', "%{$multiQualityGroupId}%");
                }
            }

            $testResults = $query->orderBy('created_at', 'desc')->get();

            if ($testResults->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => $testId
                        ? "Test result with ID {$testId} not found."
                        : "No audio extraction test results found for segment {$segmentId}" .
                          ($qualityLevel ? " with {$qualityLevel} quality." : "."),
                    'status' => 'not_found'
                ], 404);
            }

            // Get the most recent test result for progress tracking
            $latestTest = $testResults->first();

            // Calculate progress percentage based on status and timing
            $progressPercentage = 0;
            $statusMessage = 'Initializing...';

            switch ($latestTest->status) {
                case 'queued':
                    $progressPercentage = 5;
                    $statusMessage = 'Test queued for processing...';
                    break;
                case 'processing':
                    // Calculate progress based on timing if available
                    if ($latestTest->audio_extraction_started_at) {
                        $startTime = $latestTest->audio_extraction_started_at;
                        $now = now();
                        $elapsedSeconds = $now->diffInSeconds($startTime);
                        
                        // Estimate progress based on quality level and elapsed time
                        $estimatedDuration = [
                            'fast' => 30,
                            'balanced' => 60,
                            'high' => 120,
                            'premium' => 300
                        ];
                        
                        $expectedDuration = $estimatedDuration[$latestTest->test_quality_level] ?? 60;
                        $calculatedProgress = min(95, 10 + (($elapsedSeconds / $expectedDuration) * 85));
                        $progressPercentage = max(10, $calculatedProgress);
                        
                        $statusMessage = "Processing {$latestTest->test_quality_level} quality extraction... ({$elapsedSeconds}s elapsed)";
                    } else {
                        $progressPercentage = 10;
                        $statusMessage = 'Starting audio extraction...';
                    }
                    break;
                case 'completed':
                    $progressPercentage = 100;
                    $statusMessage = 'Audio extraction completed successfully!';
                    break;
                case 'failed':
                    $progressPercentage = 0;
                    $statusMessage = $latestTest->error_message ?: 'Audio extraction failed';
                    break;
                default:
                    $progressPercentage = 0;
                    $statusMessage = 'Unknown status';
            }

            // Format results with enhanced progress information
            $formattedResults = $testResults->map(function ($log) {
                return [
                    'test_id' => $log->id,
                    'status' => $log->status,
                    'quality_level' => $log->test_quality_level,
                    'extraction_settings' => $log->extraction_settings,
                    'audio_quality_metrics' => $log->audio_quality_metrics,
                    'file_info' => [
                        'original_file' => $log->file_name,
                        'file_size' => $log->file_size,
                        'extracted_audio_path' => $log->extracted_audio_path,
                        'extracted_audio_size' => $log->extracted_audio_size
                    ],
                    'processing_time' => $log->total_processing_duration_seconds,
                    'audio_extraction_duration' => $log->audio_extraction_duration_seconds,
                    'error_message' => $log->error_message,
                    'started_at' => $log->started_at,
                    'audio_extraction_started_at' => $log->audio_extraction_started_at,
                    'audio_extraction_completed_at' => $log->audio_extraction_completed_at,
                    'completed_at' => $log->completed_at,
                    'created_at' => $log->created_at,
                    'updated_at' => $log->updated_at
                ];
            });

            $response = [
                'success' => true,
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'test_count' => $testResults->count(),
                'status' => $latestTest->status,
                'progress_percentage' => round($progressPercentage, 1),
                'status_message' => $statusMessage,
                'quality_level' => $latestTest->test_quality_level,
                'results' => $testId ? $formattedResults->first() : $formattedResults->first(), // Return latest result for progress tracking
                'all_results' => $testId ? null : $formattedResults // Include all results if not requesting specific test
            ];

            // Add timing information for active tests
            if (in_array($latestTest->status, ['queued', 'processing'])) {
                $response['timing'] = [
                    'queued_at' => $latestTest->created_at,
                    'started_at' => $latestTest->started_at,
                    'audio_extraction_started_at' => $latestTest->audio_extraction_started_at,
                    'elapsed_seconds' => $latestTest->started_at ? now()->diffInSeconds($latestTest->started_at) : 0
                ];
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Error getting audio test results', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'test_id' => $request->get('test_id'),
                'quality_level' => $request->get('quality_level'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving audio test results',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'status' => 'error',
                'progress_percentage' => 0,
                'status_message' => 'Failed to retrieve test status'
            ], 500);
        }
    }

    /**
     * Get audio test history across all courses and segments
     */
    public function getAudioTestHistory(Request $request)
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'per_page' => 'sometimes|integer|min:1|max:100',
                'status' => 'sometimes|string|in:queued,processing,completed,failed',
                'quality_level' => 'sometimes|string|in:fast,balanced,high,premium',
                'course_id' => 'sometimes|integer|exists:local_truefire_courses,id'
            ]);

            $perPage = $validated['per_page'] ?? 15;

            // Build query
            $query = TranscriptionLog::where('is_test_extraction', true);

            // Apply filters
            if (isset($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            if (isset($validated['quality_level'])) {
                $query->where('test_quality_level', $validated['quality_level']);
            }

            if (isset($validated['course_id'])) {
                $courseDir = "truefire-courses/{$validated['course_id']}";
                $query->where('file_path', 'like', "%{$courseDir}%");
            }

            // Get paginated results
            $testHistory = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Format results with additional metadata
            $formattedResults = $testHistory->getCollection()->map(function ($log) {
                // Extract course and segment info from file path
                $courseId = null;
                $segmentId = null;
                
                if (preg_match('/truefire-courses\/(\d+)\/(\d+)\.mp4/', $log->file_path, $matches)) {
                    $courseId = (int) $matches[1];
                    $segmentId = (int) $matches[2];
                }

                return [
                    'test_id' => $log->id,
                    'course_id' => $courseId,
                    'segment_id' => $segmentId,
                    'status' => $log->status,
                    'quality_level' => $log->test_quality_level,
                    'extraction_settings' => $log->extraction_settings,
                    'audio_quality_metrics' => $log->audio_quality_metrics,
                    'file_info' => [
                        'original_file' => $log->file_name,
                        'file_size' => $log->file_size,
                        'extracted_audio_path' => $log->extracted_audio_path,
                        'extracted_audio_size' => $log->extracted_audio_size
                    ],
                    'processing_time' => $log->processing_time_seconds,
                    'error_message' => $log->error_message,
                    'created_at' => $log->created_at,
                    'updated_at' => $log->updated_at,
                    'completed_at' => $log->completed_at
                ];
            });

            // Replace the collection in pagination result
            $testHistory->setCollection($formattedResults);

            // Get summary statistics
            $summaryStats = [
                'total_tests' => TranscriptionLog::where('is_test_extraction', true)->count(),
                'status_breakdown' => TranscriptionLog::where('is_test_extraction', true)
                    ->selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status')
                    ->toArray(),
                'quality_level_breakdown' => TranscriptionLog::where('is_test_extraction', true)
                    ->selectRaw('test_quality_level, COUNT(*) as count')
                    ->groupBy('test_quality_level')
                    ->pluck('count', 'test_quality_level')
                    ->toArray(),
                'average_processing_time' => TranscriptionLog::where('is_test_extraction', true)
                    ->where('status', 'completed')
                    ->avg('processing_time_seconds')
            ];

            return response()->json([
                'success' => true,
                'data' => $testHistory,
                'summary_stats' => $summaryStats,
                'filters_applied' => array_intersect_key($validated, array_flip(['status', 'quality_level', 'course_id']))
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error getting audio test history', [
                'error' => $e->getMessage(),
                'filters' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving audio test history',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Create a batch audio extraction test
     */
    public function createBatchTest(LocalTruefireCourse $truefireCourse, CreateBatchTestRequest $request)
    {
        try {
            $validated = $request->validated();

            // Load course with segments to validate segment access
            $course = $truefireCourse->load(['channels.segments' => function ($query) {
                $query->withVideo(); // Only load segments with valid video fields
            }]);

            // Collect all segments from channels
            $allSegments = collect();
            foreach ($course->channels as $channel) {
                $allSegments = $allSegments->merge($channel->segments);
            }

            // Validate that all requested segments exist and belong to this course
            $availableSegmentIds = $allSegments->pluck('id')->toArray();
            $invalidSegments = array_diff($validated['segment_ids'], $availableSegmentIds);

            if (!empty($invalidSegments)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some segments do not belong to this course or do not have valid video fields.',
                    'invalid_segments' => $invalidSegments
                ], 422);
            }

            // Check if video files exist for the segments
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            $disk = 'd_drive'; // Always use d_drive for TrueFire courses
            $missingFiles = [];

            foreach ($validated['segment_ids'] as $segmentId) {
                $videoFilePath = "{$courseDir}/{$segmentId}.mp4";
                if (!Storage::disk($disk)->exists($videoFilePath)) {
                    $missingFiles[] = $segmentId;
                }
            }

            if (!empty($missingFiles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some video files are missing. Please download them first.',
                    'missing_segments' => $missingFiles,
                    'required_action' => 'Download missing video files before running batch test'
                ], 422);
            }

            // Create the batch
            $batch = AudioTestBatch::create($validated);

            // Estimate processing duration
            $batch->estimateDuration();

            Log::info('Audio test batch created for TrueFire course', [
                'batch_id' => $batch->id,
                'course_id' => $truefireCourse->id,
                'user_id' => Auth::id(),
                'total_segments' => $batch->total_segments,
                'quality_level' => $batch->quality_level
            ]);

            // Dispatch the batch processing job
            BatchAudioExtractionJob::dispatch($batch);

            return response()->json([
                'success' => true,
                'message' => 'Batch audio extraction test created and processing started',
                'data' => [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'status' => $batch->status,
                    'total_segments' => $batch->total_segments,
                    'quality_level' => $batch->quality_level,
                    'estimated_duration' => $batch->estimated_duration,
                    'concurrent_jobs' => $batch->concurrent_jobs,
                    'created_at' => $batch->created_at,
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create batch audio extraction test', [
                'course_id' => $truefireCourse->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'request_data' => $request->validated()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create batch test',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get batch test status
     */
    public function getBatchTestStatus(LocalTruefireCourse $truefireCourse, $batchId)
    {
        try {
            $batch = AudioTestBatch::where('id', $batchId)
                ->where('truefire_course_id', $truefireCourse->id)
                ->where('user_id', Auth::id())
                ->with(['transcriptionLogs'])
                ->first();

            if (!$batch) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch test not found or access denied'
                ], 404);
            }

            // Get detailed progress information
            $logs = $batch->transcriptionLogs;
            $progressDetails = [
                'queued' => $logs->where('status', 'queued')->count(),
                'processing' => $logs->where('status', 'processing')->count(),
                'completed' => $logs->where('status', 'completed')->count(),
                'failed' => $logs->where('status', 'failed')->count(),
            ];

            // Get Laravel batch information if available
            $laravelBatchInfo = null;
            if ($batch->batch_job_id) {
                try {
                    $laravelBatch = Bus::findBatch($batch->batch_job_id);
                    if ($laravelBatch) {
                        $laravelBatchInfo = [
                            'id' => $laravelBatch->id,
                            'name' => $laravelBatch->name,
                            'total_jobs' => $laravelBatch->totalJobs,
                            'processed_jobs' => $laravelBatch->processedJobs(),
                            'pending_jobs' => $laravelBatch->pendingJobs,
                            'failed_jobs' => $laravelBatch->failedJobs,
                            'cancelled' => $laravelBatch->cancelled(),
                            'finished' => $laravelBatch->finished(),
                        ];
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to retrieve Laravel batch info', [
                        'batch_id' => $batch->id,
                        'laravel_batch_id' => $batch->batch_job_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'status' => $batch->status,
                    'quality_level' => $batch->quality_level,
                    'total_segments' => $batch->total_segments,
                    'completed_segments' => $batch->completed_segments,
                    'failed_segments' => $batch->failed_segments,
                    'progress_percentage' => $batch->progress_percentage,
                    'remaining_segments' => $batch->remaining_segments,
                    'concurrent_jobs' => $batch->concurrent_jobs,
                    'estimated_duration' => $batch->estimated_duration,
                    'actual_duration' => $batch->actual_duration,
                    'estimated_time_remaining' => $batch->estimated_time_remaining,
                    'started_at' => $batch->started_at,
                    'completed_at' => $batch->completed_at,
                    'created_at' => $batch->created_at,
                    'progress_details' => $progressDetails,
                    'laravel_batch' => $laravelBatchInfo,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting batch test status', [
                'course_id' => $truefireCourse->id,
                'batch_id' => $batchId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving batch test status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get batch test results
     */
    public function getBatchTestResults(LocalTruefireCourse $truefireCourse, $batchId)
    {
        try {
            $batch = AudioTestBatch::where('id', $batchId)
                ->where('truefire_course_id', $truefireCourse->id)
                ->where('user_id', Auth::id())
                ->with(['transcriptionLogs.video'])
                ->first();

            if (!$batch) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch test not found or access denied'
                ], 404);
            }

            // Format results
            $results = $batch->transcriptionLogs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'segment_id' => $this->extractSegmentIdFromPath($log->file_path),
                    'status' => $log->status,
                    'batch_position' => $log->batch_position,
                    'quality_level' => $log->test_quality_level,
                    'extraction_settings' => $log->extraction_settings,
                    'audio_quality_metrics' => $log->audio_quality_metrics,
                    'processing_time_seconds' => $log->total_processing_duration_seconds,
                    'audio_file_size' => $log->audio_file_size,
                    'audio_duration_seconds' => $log->audio_duration_seconds,
                    'error_message' => $log->error_message,
                    'started_at' => $log->started_at,
                    'completed_at' => $log->completed_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'batch_info' => [
                        'id' => $batch->id,
                        'name' => $batch->name,
                        'status' => $batch->status,
                        'quality_level' => $batch->quality_level,
                        'total_segments' => $batch->total_segments,
                        'completed_segments' => $batch->completed_segments,
                        'failed_segments' => $batch->failed_segments,
                        'progress_percentage' => $batch->progress_percentage,
                        'actual_duration' => $batch->actual_duration,
                        'started_at' => $batch->started_at,
                        'completed_at' => $batch->completed_at,
                    ],
                    'results' => $results,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting batch test results', [
                'course_id' => $truefireCourse->id,
                'batch_id' => $batchId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving batch test results',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Cancel batch test
     */
    public function cancelBatchTest(LocalTruefireCourse $truefireCourse, $batchId)
    {
        try {
            $batch = AudioTestBatch::where('id', $batchId)
                ->where('truefire_course_id', $truefireCourse->id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$batch) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch test not found or access denied'
                ], 404);
            }

            if (!$batch->isProcessing()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch is not currently processing'
                ], 422);
            }

            // Cancel Laravel batch if it exists
            if ($batch->batch_job_id) {
                $laravelBatch = Bus::findBatch($batch->batch_job_id);
                if ($laravelBatch && !$laravelBatch->cancelled()) {
                    $laravelBatch->cancel();
                }
            }

            // Mark batch as cancelled
            $batch->markAsCancelled();

            Log::info('Batch audio extraction test cancelled', [
                'batch_id' => $batch->id,
                'course_id' => $truefireCourse->id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Batch test cancelled successfully',
                'data' => [
                    'id' => $batch->id,
                    'status' => $batch->status,
                    'completed_at' => $batch->completed_at,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error cancelling batch test', [
                'course_id' => $truefireCourse->id,
                'batch_id' => $batchId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error cancelling batch test',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Retry batch test
     */
    public function retryBatchTest(LocalTruefireCourse $truefireCourse, $batchId)
    {
        try {
            $batch = AudioTestBatch::where('id', $batchId)
                ->where('truefire_course_id', $truefireCourse->id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$batch) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch test not found or access denied'
                ], 404);
            }

            if ($batch->isProcessing()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch is currently processing'
                ], 422);
            }

            // Reset batch status and counters
            $batch->update([
                'status' => 'pending',
                'completed_segments' => 0,
                'failed_segments' => 0,
                'started_at' => null,
                'completed_at' => null,
                'actual_duration' => null,
                'batch_job_id' => null,
            ]);

            // Reset associated transcription logs
            $batch->transcriptionLogs()->update([
                'status' => 'queued',
                'started_at' => now(),
                'completed_at' => null,
                'error_message' => null,
                'audio_extraction_started_at' => null,
                'audio_extraction_completed_at' => null,
                'audio_extraction_duration_seconds' => null,
                'total_processing_duration_seconds' => null,
                'progress_percentage' => 0,
            ]);

            // Dispatch new batch processing job
            BatchAudioExtractionJob::dispatch($batch);

            Log::info('Batch audio extraction test retried', [
                'batch_id' => $batch->id,
                'course_id' => $truefireCourse->id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Batch test retry started successfully',
                'data' => [
                    'id' => $batch->id,
                    'status' => $batch->status,
                    'total_segments' => $batch->total_segments,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrying batch test', [
                'course_id' => $truefireCourse->id,
                'batch_id' => $batchId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrying batch test',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Delete batch test
     */
    public function deleteBatchTest(LocalTruefireCourse $truefireCourse, $batchId)
    {
        try {
            $batch = AudioTestBatch::where('id', $batchId)
                ->where('truefire_course_id', $truefireCourse->id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$batch) {
                return response()->json([
                    'success' => false,
                    'message' => 'Batch test not found or access denied'
                ], 404);
            }

            if ($batch->isProcessing()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete batch while processing. Cancel it first.'
                ], 422);
            }

            // Cancel Laravel batch if it exists
            if ($batch->batch_job_id) {
                try {
                    $laravelBatch = Bus::findBatch($batch->batch_job_id);
                    if ($laravelBatch && !$laravelBatch->cancelled()) {
                        $laravelBatch->cancel();
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to cancel Laravel batch during deletion', [
                        'batch_id' => $batch->id,
                        'laravel_batch_id' => $batch->batch_job_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Delete the batch (transcription logs will be set to null due to foreign key constraint)
            $batch->delete();

            Log::info('Batch audio extraction test deleted', [
                'batch_id' => $batchId,
                'course_id' => $truefireCourse->id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Batch test deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting batch test', [
                'course_id' => $truefireCourse->id,
                'batch_id' => $batchId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error deleting batch test',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Extract segment ID from file path
     */
    private function extractSegmentIdFromPath($filePath)
    {
        if (preg_match('/\/(\d+)\.mp4$/', $filePath, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * Set audio extraction preset for a course
     */
    public function setAudioPreset(LocalTruefireCourse $truefireCourse, Request $request)
    {
        try {
            $validated = $request->validate([
                'preset' => 'required|string|in:fast,balanced,high,premium',
                'settings' => 'sometimes|array'
            ]);

            $oldPreset = $truefireCourse->getAudioExtractionPreset();
            $settings = $validated['settings'] ?? [];
            
            // Use the pivot table approach
            CourseAudioPreset::updateForCourse(
                $truefireCourse->id,
                $validated['preset'],
                $settings
            );

            Log::info('Audio extraction preset updated for course', [
                'course_id' => $truefireCourse->id,
                'old_preset' => $oldPreset,
                'new_preset' => $validated['preset'],
                'settings' => $settings,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => "Audio extraction preset updated to {$validated['preset']}",
                'data' => [
                    'course_id' => $truefireCourse->id,
                    'preset' => $validated['preset'],
                    'previous_preset' => $oldPreset,
                    'settings' => $settings
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error setting audio extraction preset', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error setting audio extraction preset',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get current audio extraction preset for a course
     */
    public function getAudioPreset(LocalTruefireCourse $truefireCourse)
    {
        try {
            // Use the pivot table approach
            $preset = CourseAudioPreset::getPresetForCourse($truefireCourse->id);
            $settings = CourseAudioPreset::getSettingsForCourse($truefireCourse->id);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'course_id' => $truefireCourse->id,
                    'preset' => $preset,
                    'settings' => $settings,
                    'available_presets' => CourseAudioPreset::getAvailablePresets()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting audio extraction preset', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error getting audio extraction preset',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Process all videos in a course for transcription workflow
     */
    public function processAllVideos(LocalTruefireCourse $truefireCourse, Request $request)
    {
        try {
            $validated = $request->validate([
                'for_transcription' => 'sometimes|boolean',
                'settings' => 'sometimes|array',
                'settings.sample_rate' => 'sometimes|integer|in:16000,22050,44100,48000',
                'settings.bit_rate' => 'sometimes|string|in:128k,192k,256k,320k',
                'settings.channels' => 'sometimes|integer|in:1,2'
            ]);

            $forTranscription = $validated['for_transcription'] ?? true;
            $settings = $validated['settings'] ?? [];

            // Load course with segments to validate
            $course = $truefireCourse->load(['channels.segments' => function ($query) {
                $query->withVideo(); // Only load segments with valid video fields
            }]);

            // Collect all segments from channels
            $allSegments = collect();
            foreach ($course->channels as $channel) {
                $allSegments = $allSegments->merge($channel->segments);
            }

            if ($allSegments->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No segments with valid video fields found for this course.',
                    'course_id' => $truefireCourse->id
                ], 404);
            }

            // Check if video files exist for the segments
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            $disk = 'd_drive'; // Always use d_drive for TrueFire courses
            $missingFiles = [];
            $availableSegments = 0;

            foreach ($allSegments as $segment) {
                $videoFilePath = "{$courseDir}/{$segment->id}.mp4";
                if (Storage::disk($disk)->exists($videoFilePath)) {
                    $availableSegments++;
                } else {
                    $missingFiles[] = $segment->id;
                }
            }

            if ($availableSegments === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No video files found for this course. Please download videos first.',
                    'missing_segments' => $missingFiles,
                    'total_segments' => $allSegments->count(),
                    'available_segments' => $availableSegments
                ], 422);
            }

            // Dispatch the course processing job
            \App\Jobs\ProcessCourseAudioExtractionJob::dispatch(
                $truefireCourse,
                $forTranscription,
                $settings
            );

            $jobId = 'course_audio_extract_' . $truefireCourse->id . '_' . time() . '_' . uniqid();

            Log::info('Course audio extraction processing started', [
                'course_id' => $truefireCourse->id,
                'course_title' => $truefireCourse->title ?? "Course #{$truefireCourse->id}",
                'preset' => $truefireCourse->getAudioExtractionPreset(),
                'for_transcription' => $forTranscription,
                'total_segments' => $allSegments->count(),
                'available_segments' => $availableSegments,
                'missing_segments' => count($missingFiles),
                'job_id' => $jobId,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => $forTranscription
                    ? "Course audio extraction for transcription started with {$truefireCourse->getAudioExtractionPreset()} quality"
                    : "Course audio extraction for testing started with {$truefireCourse->getAudioExtractionPreset()} quality",
                'data' => [
                    'course_id' => $truefireCourse->id,
                    'course_title' => $truefireCourse->title ?? "Course #{$truefireCourse->id}",
                    'preset' => $truefireCourse->getAudioExtractionPreset(),
                    'for_transcription' => $forTranscription,
                    'output_format' => 'wav', // Always WAV for Whisper compatibility
                    'total_segments' => $allSegments->count(),
                    'available_segments' => $availableSegments,
                    'missing_segments' => count($missingFiles),
                    'job_id' => $jobId,
                    'processing_started_at' => now()->toISOString()
                ],
                'background_processing' => true,
                'workflow_info' => [
                    'next_step' => 'Individual audio extraction jobs will be queued for each segment',
                    'expected_output' => $forTranscription
                        ? 'WAV files with simple naming for transcription workflow (Whisper compatible)'
                        : 'WAV files with quality suffix for testing',
                    'monitoring' => 'Check TranscriptionLog for progress tracking'
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error starting course audio extraction processing', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error starting course audio extraction processing',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get course-level audio extraction progress
     */
    public function getCourseAudioExtractionProgress(LocalTruefireCourse $truefireCourse, Request $request)
    {
        try {
            $jobId = $request->get('job_id');

            // Build query for course-level transcription logs
            $query = TranscriptionLog::where('extraction_settings->course_id', $truefireCourse->id)
                ->where('extraction_settings->batch_processing', true);

            if ($jobId) {
                $query->where('job_id', 'like', "%{$jobId}%");
            }

            // Get master log (course-level tracking)
            $masterLog = $query->where('file_name', 'like', "Course #{$truefireCourse->id}%")
                ->orderBy('created_at', 'desc')
                ->first();

            // Get individual segment logs
            $segmentLogs = TranscriptionLog::where('extraction_settings->course_id', $truefireCourse->id)
                ->where('extraction_settings->course_batch_processing', true)
                ->where('file_name', 'not like', "Course #{$truefireCourse->id}%")
                ->orderBy('created_at', 'desc')
                ->get();

            if (!$masterLog && $segmentLogs->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No course audio extraction processing found',
                    'course_id' => $truefireCourse->id
                ], 404);
            }

            // Calculate progress statistics
            $totalSegments = $segmentLogs->count();
            $completedSegments = $segmentLogs->where('status', 'completed')->count();
            $failedSegments = $segmentLogs->where('status', 'failed')->count();
            $processingSegments = $segmentLogs->where('status', 'processing')->count();
            $queuedSegments = $segmentLogs->where('status', 'queued')->count();

            $progressPercentage = $totalSegments > 0 ? round(($completedSegments / $totalSegments) * 100, 1) : 0;

            $response = [
                'success' => true,
                'course_id' => $truefireCourse->id,
                'course_title' => $truefireCourse->title ?? "Course #{$truefireCourse->id}",
                'preset' => $truefireCourse->getAudioExtractionPreset(),
                'progress' => [
                    'total_segments' => $totalSegments,
                    'completed_segments' => $completedSegments,
                    'failed_segments' => $failedSegments,
                    'processing_segments' => $processingSegments,
                    'queued_segments' => $queuedSegments,
                    'progress_percentage' => $progressPercentage
                ],
                'status' => $this->determineCourseProcessingStatus($completedSegments, $failedSegments, $processingSegments, $queuedSegments, $totalSegments),
                'segment_details' => $segmentLogs->map(function ($log) {
                    return [
                        'segment_id' => $this->extractSegmentIdFromPath($log->file_path),
                        'status' => $log->status,
                        'quality_level' => $log->test_quality_level,
                        'processing_time' => $log->total_processing_duration_seconds,
                        'error_message' => $log->error_message,
                        'started_at' => $log->started_at,
                        'completed_at' => $log->completed_at
                    ];
                })
            ];

            // Add master log information if available
            if ($masterLog) {
                $response['master_log'] = [
                    'job_id' => $masterLog->job_id,
                    'status' => $masterLog->status,
                    'started_at' => $masterLog->started_at,
                    'completed_at' => $masterLog->completed_at,
                    'total_processing_time' => $masterLog->total_processing_duration_seconds,
                    'settings' => $masterLog->extraction_settings
                ];
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Error getting course audio extraction progress', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error getting course audio extraction progress',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Determine the overall course processing status
     */
    private function determineCourseProcessingStatus(int $completed, int $failed, int $processing, int $queued, int $total): string
    {
        if ($total === 0) {
            return 'no_segments';
        }

        if ($processing > 0 || $queued > 0) {
            return 'processing';
        }

        if ($completed === $total) {
            return 'completed';
        }

        if ($failed === $total) {
            return 'failed';
        }

        if ($completed + $failed === $total) {
            return $failed > 0 ? 'completed_with_errors' : 'completed';
        }

        return 'unknown';
    }

    /**
     * Get transcription preset for a course
     */
    public function getTranscriptionPreset(LocalTruefireCourse $truefireCourse)
    {
        try {
            $preset = CourseTranscriptionPreset::getPresetForCourse($truefireCourse->id);
            $settings = CourseTranscriptionPreset::getSettingsForCourse($truefireCourse->id);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'course_id' => $truefireCourse->id,
                    'course_title' => $truefireCourse->title ?? "Course #{$truefireCourse->id}",
                    'preset' => $preset,
                    'settings' => $settings,
                    'available_presets' => CourseTranscriptionPreset::getAvailablePresets()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting transcription preset', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error getting transcription preset',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Update transcription preset for a course
     */
    public function updateTranscriptionPreset(LocalTruefireCourse $truefireCourse, Request $request)
    {
        try {
            $validated = $request->validate([
                'preset' => 'required|string|in:fast,balanced,high,premium',
                'settings' => 'sometimes|array'
            ]);

            $oldPreset = CourseTranscriptionPreset::getPresetForCourse($truefireCourse->id);
            $settings = $validated['settings'] ?? [];
            
            // Update the course transcription preset
            CourseTranscriptionPreset::updateForCourse(
                $truefireCourse->id,
                $validated['preset'],
                $settings
            );

            Log::info('Transcription preset updated for course', [
                'course_id' => $truefireCourse->id,
                'old_preset' => $oldPreset,
                'new_preset' => $validated['preset'],
                'settings' => $settings,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => "Transcription preset updated to {$validated['preset']}",
                'data' => [
                    'course_id' => $truefireCourse->id,
                    'preset' => $validated['preset'],
                    'previous_preset' => $oldPreset,
                    'settings' => $settings
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating transcription preset', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error updating transcription preset',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get available transcription preset options
     */
    public function getTranscriptionPresetOptions()
    {
        try {
            $presets = CourseTranscriptionPreset::getAvailablePresets();
            $presetConfig = config('transcription_presets.presets', []);
            
            // Enhance preset data with configuration details
            $enhancedPresets = [];
            foreach ($presets as $presetName) {
                $config = $presetConfig[$presetName] ?? [];
                $enhancedPresets[$presetName] = [
                    'name' => $presetName,
                    'display_name' => ucfirst($presetName),
                    'description' => $config['description'] ?? "Transcription preset: {$presetName}",
                    'model' => $config['model'] ?? 'whisper-1',
                    'language' => $config['language'] ?? 'en',
                    'temperature' => $config['temperature'] ?? 0.0,
                    'response_format' => $config['response_format'] ?? 'verbose_json',
                    'timestamp_granularities' => $config['timestamp_granularities'] ?? ['segment'],
                    'recommended_for' => $config['recommended_for'] ?? []
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'presets' => $enhancedPresets,
                    'default_preset' => config('transcription_presets.default_preset', 'balanced'),
                    'available_models' => config('transcription_presets.available_models', ['whisper-1']),
                    'supported_languages' => config('transcription_presets.supported_languages', ['en'])
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting transcription preset options', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error getting transcription preset options',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Show a specific TrueFire course segment with video player and transcription functionality
     */
    public function showSegment(LocalTruefireCourse $truefireCourse, $segmentId)
    {
        // Find the segment and ensure it belongs to the course
        $segment = LocalTruefireSegment::with('channel')->findOrFail($segmentId);
        
        // Verify the segment belongs to this course through the channel
        if ($segment->channel->courseid != $truefireCourse->id) {
            abort(404, 'Segment not found in this course');
        }
        
        // Get or create processing record
        $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
        
        if (!$processing) {
            $processing = TruefireSegmentProcessing::create([
                'segment_id' => $segmentId,
                'course_id' => $truefireCourse->id,
                'status' => 'ready',
                'progress_percentage' => 0
            ]);
        }
        
        // Check if local video file exists and use it, otherwise fallback to S3
        $courseDir = "truefire-courses/{$truefireCourse->id}";
        $videoFilename = "{$segmentId}.mp4";
        $videoPath = "{$courseDir}/{$videoFilename}";
        $disk = 'd_drive';
        
        $videoUrl = null;
        $isLocalVideo = false;
        
        if (Storage::disk($disk)->exists($videoPath)) {
            // Use local video file - serve it through Laravel
            $videoUrl = route('truefire-courses.segment.video', [
                'truefireCourse' => $truefireCourse->id,
                'segment' => $segmentId
            ]);
            $isLocalVideo = true;
            Log::info('Using local video file for segment', [
                'segment_id' => $segmentId,
                'local_path' => $videoPath
            ]);
        } else {
            // Fallback to S3 signed URL
            try {
                $videoUrl = $segment->getSignedUrl();
                Log::info('Using S3 signed URL for segment (local file not found)', [
                    'segment_id' => $segmentId,
                    'expected_path' => $videoPath
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to generate S3 signed URL for segment', [
                    'segment_id' => $segmentId,
                    'error' => $e->getMessage()
                ]);
                $videoUrl = null;
            }
        }
        
        // Build segment data with processing information
        $segmentData = [
            'id' => $segment->id,
            'title' => $segment->title,
            'name' => $segment->name,
            'description' => $segment->description,
            'runtime' => $segment->runtime,
            'channel_id' => $segment->channel_id,
            'channel' => $segment->channel,
            'course_id' => $truefireCourse->id,
            
            // Video URL (prioritizes local file)
            'url' => $videoUrl,
            'video' => $segment->video,
            'is_local_video' => $isLocalVideo,
            
            // Processing status
            'status' => $processing->status,
            'error_message' => $processing->error_message,
            'is_processing' => $processing->is_processing,
            
            // Audio files
            'audio_path' => $processing->audio_path,
            'audio_url' => $processing->audio_url,
            'audio_size' => $processing->audio_size,
            'audio_duration' => $processing->audio_duration,
            'formatted_duration' => $processing->formatted_duration,
            'audio_extraction_approved' => $processing->audio_extraction_approved,
            
            // Transcript files
            'transcript_path' => $processing->transcript_path,
            'transcript_url' => $processing->transcript_url,
            'transcript_text' => $processing->transcript_text,
            'transcript_json_url' => $processing->transcript_json_url,
            'transcript_json_api_url' => ($processing->transcript_path || $processing->transcript_text) ? 
                "/api/truefire-courses/{$truefireCourse->id}/segments/{$segmentId}/transcript-json" : null,
            'subtitles_url' => $processing->subtitles_url,
            
            // Terminology
            'has_terminology' => $processing->has_terminology,
            'terminology_path' => $processing->terminology_path,
            'terminology_url' => $processing->terminology_url,
            'terminology_json_api_url' => $processing->has_terminology ? 
                "/api/truefire-courses/{$truefireCourse->id}/segments/{$segmentId}/terminology-json" : null,
            'terminology_count' => $processing->terminology_count,
            'terminology_metadata' => $processing->terminology_metadata,
            
            // Timestamps
            'created_at' => $segment->created_at,
            'updated_at' => $processing->updated_at,
        ];
        
        Log::info('Showing TrueFire course segment', [
            'course_id' => $truefireCourse->id,
            'segment_id' => $segmentId,
            'status' => $processing->status,
            'has_video_url' => !empty($segmentData['url'])
        ]);
        
        return Inertia::render('TruefireCourses/SegmentShow', [
            'course' => $truefireCourse,
            'segment' => $segmentData
        ]);
    }

    /**
     * Request transcription for a TrueFire course segment
     */
    public function requestSegmentTranscription(LocalTruefireCourse $truefireCourse, $segmentId, Request $request)
    {
        try {
            $segment = LocalTruefireSegment::findOrFail($segmentId);
            
            // Verify segment belongs to course
            if ($segment->channel->courseid != $truefireCourse->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Segment not found in this course'
                ], 404);
            }
            
            // Get or create processing record
            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
            
            if (!$processing) {
                $processing = TruefireSegmentProcessing::create([
                    'segment_id' => $segmentId,
                    'course_id' => $truefireCourse->id,
                    'status' => 'ready',
                    'progress_percentage' => 0
                ]);
            }
            
            // Check if already processing
            if ($processing->is_processing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Segment is already being processed'
                ], 400);
            }
            
            // Start audio extraction first
            $processing->startAudioExtraction();
            
            // Dispatch audio extraction job
            TruefireSegmentAudioExtractionJob::dispatch($processing);
            
            Log::info('TrueFire segment transcription requested', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Audio extraction and transcription process started'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error requesting TrueFire segment transcription', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error starting transcription: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restart transcription for a failed TrueFire course segment
     */
    public function restartSegmentTranscription(LocalTruefireCourse $truefireCourse, $segmentId, Request $request)
    {
        try {
            $segment = LocalTruefireSegment::findOrFail($segmentId);
            
            // Verify segment belongs to course
            if ($segment->channel->courseid != $truefireCourse->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Segment not found in this course'
                ], 404);
            }
            
            // Get processing record
            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
            
            if (!$processing) {
                return response()->json([
                    'success' => false,
                    'message' => 'No processing record found for this segment'
                ], 400);
            }
            
            // Check if status allows restart
            if ($processing->status !== 'failed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only restart failed transcriptions. Current status: ' . $processing->status
                ], 400);
            }
            
            // Check if already processing
            if ($processing->is_processing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Segment is already being processed'
                ], 400);
            }
            
            // Reset processing record for restart
            $processing->update([
                'status' => 'ready',
                'is_processing' => false,
                'error_message' => null,
                'progress_percentage' => 0,
                
                // Clear previous processing data but keep successful parts
                'audio_extraction_started_at' => null,
                'audio_extraction_completed_at' => null,
                'transcription_started_at' => null,
                'transcription_completed_at' => null,
                'terminology_started_at' => null,
                'terminology_completed_at' => null,
                
                // Keep successful file paths/URLs if they exist
                // 'audio_path' => null,  // Keep if audio was successful
                // 'audio_url' => null,   // Keep if audio was successful
                // 'transcript_path' => null,  // Keep if transcript was successful
                // 'transcript_url' => null,   // Keep if transcript was successful
                // 'terminology_path' => null, // Keep if terminology was successful
                // 'terminology_url' => null,  // Keep if terminology was successful
            ]);
            
            // Start audio extraction from the beginning
            $processing->startAudioExtraction();
            
            // Dispatch audio extraction job
            TruefireSegmentAudioExtractionJob::dispatch($processing);
            
            Log::info('TrueFire segment transcription restarted', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'previous_error' => $processing->getOriginal('error_message')
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Transcription process restarted'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error restarting TrueFire segment transcription', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error restarting transcription: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve audio extraction for a TrueFire course segment
     */
    public function approveSegmentAudioExtraction(LocalTruefireCourse $truefireCourse, $segmentId, Request $request)
    {
        try {
            $segment = LocalTruefireSegment::findOrFail($segmentId);
            
            // Verify segment belongs to course
            if ($segment->channel->courseid != $truefireCourse->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Segment not found in this course'
                ], 404);
            }
            
            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
            
            if (!$processing) {
                return response()->json([
                    'success' => false,
                    'message' => 'No processing record found'
                ], 400);
            }
            
            // Validate request data
            $validated = $request->validate([
                'approved_by' => 'required|string|max:255',
                'notes' => 'nullable|string|max:1000'
            ]);
            
            // Update processing record
            $processing->update([
                'audio_extraction_approved' => true,
                'audio_extraction_approved_at' => now(),
                'audio_extraction_approved_by' => $validated['approved_by'],
                'audio_extraction_notes' => $validated['notes'] ?? null
            ]);
            
            // Start transcription
            $processing->startTranscription();
            
            // Dispatch transcription job
            TruefireSegmentTranscriptionJob::dispatch($processing)->onQueue('transcription');
            
            Log::info('TrueFire segment audio extraction approved', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'approved_by' => $validated['approved_by']
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Audio extraction approved and transcription started'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error approving TrueFire segment audio extraction', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error approving audio extraction: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Request terminology recognition for a TrueFire course segment - DISABLED
     */
    public function requestSegmentTerminology(LocalTruefireCourse $truefireCourse, $segmentId)
    {
        return response()->json([
            'success' => false,
            'message' => 'Terminology recognition is currently disabled'
        ], 400);
        
        /*
        try {
            $segment = LocalTruefireSegment::findOrFail($segmentId);
            
            // Verify segment belongs to course
            if ($segment->channel->courseid != $truefireCourse->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Segment not found in this course'
                ], 404);
            }
            
            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
            
            if (!$processing) {
                return response()->json([
                    'success' => false,
                    'message' => 'No processing record found'
                ], 400);
            }
            
            // Check if transcript is available
            if (empty($processing->transcript_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transcript not available. Please complete transcription first.'
                ], 400);
            }
            
            // Start terminology processing
            $processing->startTerminology();
            
            // Dispatch terminology job
            TruefireSegmentTerminologyJob::dispatch($processing);
            
            Log::info('TrueFire segment terminology recognition requested', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Terminology recognition started'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error requesting TrueFire segment terminology', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error starting terminology recognition: ' . $e->getMessage()
            ], 500);
        }
        */
    }

    /**
     * Serve the local video file for a TrueFire segment
     */
    public function serveSegmentVideo(LocalTruefireCourse $truefireCourse, $segmentId)
    {
        try {
            // Verify segment belongs to course
            $segment = LocalTruefireSegment::with('channel')->findOrFail($segmentId);
            
            if ($segment->channel->courseid != $truefireCourse->id) {
                abort(404, 'Segment not found in this course');
            }
            
            // Build local video path
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            $videoFilename = "{$segmentId}.mp4";
            $videoPath = "{$courseDir}/{$videoFilename}";
            $disk = 'd_drive';
            
            // Check if local file exists
            if (!Storage::disk($disk)->exists($videoPath)) {
                Log::warning('Local video file not found', [
                    'course_id' => $truefireCourse->id,
                    'segment_id' => $segmentId,
                    'path' => $videoPath
                ]);
                abort(404, 'Video file not found locally');
            }
            
            // Get the full file path
            $fullPath = Storage::disk($disk)->path($videoPath);
            
            // Verify file exists and is readable
            if (!file_exists($fullPath) || !is_readable($fullPath)) {
                Log::error('Video file exists in storage but not accessible', [
                    'segment_id' => $segmentId,
                    'full_path' => $fullPath
                ]);
                abort(404, 'Video file not accessible');
            }
            
            // Get file size and MIME type
            $fileSize = filesize($fullPath);
            $mimeType = 'video/mp4';
            
            Log::info('Serving local video file', [
                'segment_id' => $segmentId,
                'file_size' => $fileSize,
                'path' => $videoPath
            ]);
            
            // Set appropriate headers for video streaming
            $headers = [
                'Content-Type' => $mimeType,
                'Content-Length' => $fileSize,
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'public, max-age=3600',
            ];
            
            // Handle range requests for video seeking
            $request = request();
            if ($request->hasHeader('Range')) {
                $range = $request->header('Range');
                if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
                    $start = intval($matches[1]);
                    $end = !empty($matches[2]) ? intval($matches[2]) : $fileSize - 1;
                    $length = $end - $start + 1;
                    
                    $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";
                    $headers['Content-Length'] = $length;
                    
                    $stream = fopen($fullPath, 'rb');
                    fseek($stream, $start);
                    $data = fread($stream, $length);
                    fclose($stream);
                    
                    return response($data, 206, $headers);
                }
            }
            
            // Return full file
            return response()->file($fullPath, $headers);
            
        } catch (\Exception $e) {
            Log::error('Error serving TrueFire segment video', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'error' => $e->getMessage()
            ]);
            
            abort(500, 'Error serving video file');
        }
    }

    /**
     * Serve the extracted audio file for a TrueFire segment
     */
    public function serveSegmentAudio(LocalTruefireCourse $truefireCourse, $segmentId, $filename)
    {
        try {
            // Verify segment belongs to course
            $segment = LocalTruefireSegment::with('channel')->findOrFail($segmentId);
            
            if ($segment->channel->courseid != $truefireCourse->id) {
                abort(404, 'Segment not found in this course');
            }
            
            // Build audio file path - check both in course directory and root d_drive
            $possiblePaths = [
                "truefire-courses/{$truefireCourse->id}/{$filename}",  // In course directory
                $filename  // In root d_drive (for files like 7959.wav)
            ];
            
            $disk = 'd_drive';
            $audioPath = null;
            $fullPath = null;
            
            // Find the audio file in one of the possible locations
            foreach ($possiblePaths as $path) {
                if (Storage::disk($disk)->exists($path)) {
                    $audioPath = $path;
                    $fullPath = Storage::disk($disk)->path($path);
                    break;
                }
            }
            
            // If not found in storage, check direct paths on D drive
            if (!$audioPath) {
                $directPaths = [
                    "/mnt/d_drive/truefire-courses/{$truefireCourse->id}/{$filename}",
                    "/mnt/d_drive/{$filename}"
                ];
                
                foreach ($directPaths as $directPath) {
                    if (file_exists($directPath)) {
                        $fullPath = $directPath;
                        break;
                    }
                }
            }
            
            if (!$fullPath || !file_exists($fullPath) || !is_readable($fullPath)) {
                Log::warning('Audio file not found', [
                    'course_id' => $truefireCourse->id,
                    'segment_id' => $segmentId,
                    'filename' => $filename,
                    'checked_paths' => $possiblePaths
                ]);
                abort(404, 'Audio file not found');
            }
            
            // Get file size and MIME type
            $fileSize = filesize($fullPath);
            $mimeType = 'audio/wav';
            
            Log::info('Serving audio file', [
                'segment_id' => $segmentId,
                'filename' => $filename,
                'file_size' => $fileSize,
                'path' => $fullPath
            ]);
            
            // Set appropriate headers for audio streaming
            $headers = [
                'Content-Type' => $mimeType,
                'Content-Length' => $fileSize,
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'public, max-age=3600',
                'Content-Disposition' => 'inline; filename="' . basename($filename) . '"'
            ];
            
            // Handle range requests for audio seeking
            $request = request();
            if ($request->hasHeader('Range')) {
                $range = $request->header('Range');
                if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
                    $start = intval($matches[1]);
                    $end = !empty($matches[2]) ? intval($matches[2]) : $fileSize - 1;
                    $length = $end - $start + 1;
                    
                    $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";
                    $headers['Content-Length'] = $length;
                    
                    $stream = fopen($fullPath, 'rb');
                    fseek($stream, $start);
                    $data = fread($stream, $length);
                    fclose($stream);
                    
                    return response($data, 206, $headers);
                }
            }
            
            // Return full file
            return response()->file($fullPath, $headers);
            
        } catch (\Exception $e) {
            Log::error('Error serving TrueFire segment audio', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            
            abort(500, 'Error serving audio file');
        }
    }

    /**
     * Process all audio extractions for a course using intelligent selection
     */
    public function processAllAudioExtractions(LocalTruefireCourse $truefireCourse, Request $request)
    {
        try {
            $validated = $request->validate([
                'enable_intelligent_extraction' => 'sometimes|boolean',
                'force_restart' => 'sometimes|boolean',
                'continue_existing' => 'sometimes|boolean',
                'settings' => 'sometimes|array'
            ]);

            $enableIntelligentExtraction = $validated['enable_intelligent_extraction'] ?? true;
            $forceRestart = $validated['force_restart'] ?? false;
            $continueExisting = $validated['continue_existing'] ?? false;
            $settings = $validated['settings'] ?? [];

            // Load course with segments that have video files available
            $course = $truefireCourse->load(['channels.segments' => function ($query) {
                $query->withVideo(); // Only load segments with valid video fields
            }]);

            // Collect all segments from channels
            $allSegments = collect();
            foreach ($course->channels as $channel) {
                $allSegments = $allSegments->merge($channel->segments);
            }

            if ($allSegments->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No segments found for this course.',
                    'missing_segments' => [],
                    'total_segments' => 0,
                    'available_segments' => 0
                ], 422);
            }

            $courseDir = "truefire-courses/{$truefireCourse->id}";
            $disk = 'd_drive';
            $availableSegments = 0;
            $alreadyProcessed = 0;
            $missingVideoFiles = [];
            $segmentsToProcess = [];

            // Check which segments have video files and which already have audio files
            foreach ($allSegments as $segment) {
                $videoFilename = "{$segment->id}.mp4";
                $videoFilePath = "{$courseDir}/{$videoFilename}";
                $audioFilename = "{$segment->id}.wav";
                $audioFilePath = "{$courseDir}/{$audioFilename}";

                if (Storage::disk($disk)->exists($videoFilePath)) {
                    $availableSegments++;
                    
                    $hasAudioFile = Storage::disk($disk)->exists($audioFilePath);
                    
                    if ($hasAudioFile && !$forceRestart && $continueExisting) {
                        $alreadyProcessed++;
                    } else {
                        $segmentsToProcess[] = $segment->id;
                    }
                } else {
                    $missingVideoFiles[] = $segment->id;
                }
            }

            if ($availableSegments === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No video files found for audio extraction. Please download videos first.',
                    'missing_video_files' => $missingVideoFiles,
                    'total_segments' => $allSegments->count(),
                    'available_segments' => $availableSegments,
                    'required_action' => 'Download course videos first'
                ], 422);
            }

            $segmentsToProcessCount = count($segmentsToProcess);
            
            if ($segmentsToProcessCount === 0 && !$forceRestart) {
                return response()->json([
                    'success' => true,
                    'message' => 'All segments already have audio files. Use force_restart to reprocess.',
                    'data' => [
                        'course_id' => $truefireCourse->id,
                        'total_segments' => $allSegments->count(),
                        'available_segments' => $availableSegments,
                        'already_processed' => $alreadyProcessed,
                        'segments_to_process' => 0,
                        'action_taken' => 'none'
                    ]
                ]);
            }

            // Dispatch job with only the segments that need processing (intelligent continuation)
            \App\Jobs\ProcessCourseAudioExtractionJob::dispatch(
                $truefireCourse,
                true, // for_transcription
                array_merge($settings, [
                    'enable_intelligent_extraction' => $enableIntelligentExtraction,
                    'force_restart' => $forceRestart,
                    'continue_existing' => $continueExisting
                ]),
                $segmentsToProcess // Only process segments that need work
            );

            $jobId = 'course_intelligent_audio_' . $truefireCourse->id . '_' . time() . '_' . uniqid();

            Log::info('Course intelligent audio extraction started', [
                'course_id' => $truefireCourse->id,
                'course_title' => $truefireCourse->title ?? "Course #{$truefireCourse->id}",
                'intelligent_extraction' => $enableIntelligentExtraction,
                'force_restart' => $forceRestart,
                'continue_existing' => $continueExisting,
                'total_segments' => $allSegments->count(),
                'available_segments' => $availableSegments,
                'already_processed' => $alreadyProcessed,
                'segments_to_process' => $segmentsToProcessCount,
                'missing_video_files' => count($missingVideoFiles),
                'job_id' => $jobId,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => $enableIntelligentExtraction 
                    ? 'Intelligent audio extraction started successfully'
                    : 'Audio extraction started successfully',
                'data' => [
                    'job_id' => $jobId,
                    'course_id' => $truefireCourse->id,
                    'course_title' => $truefireCourse->title ?? "Course #{$truefireCourse->id}",
                    'intelligent_extraction_enabled' => $enableIntelligentExtraction,
                    'total_segments' => $allSegments->count(),
                    'available_segments' => $availableSegments,
                    'already_processed' => $alreadyProcessed,
                    'segments_to_process' => $segmentsToProcessCount,
                    'missing_video_files' => count($missingVideoFiles),
                    'force_restart' => $forceRestart,
                    'continue_existing' => $continueExisting,
                    'estimated_duration_minutes' => ceil($segmentsToProcessCount * 1.5),
                    'started_at' => now()->toISOString(),
                    'processing_mode' => $enableIntelligentExtraction ? 'intelligent_cascading' : 'fixed_quality'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error starting course intelligent audio extraction', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error starting course audio extraction',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Process all segments in a course for transcription workflow
     */
    public function processAllTranscriptions(LocalTruefireCourse $truefireCourse, Request $request)
    {
        try {
            $validated = $request->validate([
                'force_restart' => 'sometimes|boolean',
                'preset' => 'sometimes|string|in:fast,balanced,high,premium',
                'settings' => 'sometimes|array'
            ]);

            $forceRestart = $validated['force_restart'] ?? false;
            $preset = $validated['preset'] ?? $truefireCourse->getTranscriptionPreset();
            $settings = $validated['settings'] ?? [];

            // Load course with segments that have audio files available
            $course = $truefireCourse->load(['channels.segments' => function ($query) {
                $query->withVideo(); // Only load segments with valid video fields
            }]);

            // Collect all segments from channels
            $allSegments = collect();
            foreach ($course->channels as $channel) {
                $allSegments = $allSegments->merge($channel->segments);
            }

            if ($allSegments->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No segments found for this course.',
                    'missing_segments' => [],
                    'total_segments' => 0,
                    'available_segments' => 0
                ], 422);
            }

            $courseDir = "truefire-courses/{$truefireCourse->id}";
            $disk = 'd_drive';
            $availableSegments = 0;
            $missingAudioFiles = [];

            // Check which segments have audio files available
            foreach ($allSegments as $segment) {
                $audioFilename = "{$segment->id}.wav";
                $audioFilePath = "{$courseDir}/{$audioFilename}";

                if (Storage::disk($disk)->exists($audioFilePath)) {
                    $availableSegments++;
                } else {
                    $missingAudioFiles[] = $segment->id;
                }
            }

            if ($availableSegments === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No audio files found for transcription. Please run audio extraction first.',
                    'missing_audio_files' => $missingAudioFiles,
                    'total_segments' => $allSegments->count(),
                    'available_segments' => $availableSegments,
                    'required_action' => 'Run audio extraction batch processing first'
                ], 422);
            }

            // Dispatch the course transcription processing job
            \App\Jobs\ProcessCourseTranscriptionJob::dispatch(
                $truefireCourse,
                $preset,
                $settings,
                $forceRestart
            );

            $jobId = 'course_transcription_' . $truefireCourse->id . '_' . time() . '_' . uniqid();

            Log::info('Course transcription processing started', [
                'course_id' => $truefireCourse->id,
                'course_title' => $truefireCourse->title ?? "Course #{$truefireCourse->id}",
                'preset' => $preset,
                'total_segments' => $allSegments->count(),
                'available_segments' => $availableSegments,
                'missing_audio_files' => count($missingAudioFiles),
                'force_restart' => $forceRestart,
                'job_id' => $jobId,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Course transcription processing started successfully',
                'data' => [
                    'job_id' => $jobId,
                    'course_id' => $truefireCourse->id,
                    'course_title' => $truefireCourse->title ?? "Course #{$truefireCourse->id}",
                    'preset' => $preset,
                    'total_segments' => $allSegments->count(),
                    'available_segments' => $availableSegments,
                    'missing_audio_files' => count($missingAudioFiles),
                    'force_restart' => $forceRestart,
                    'estimated_duration_minutes' => ceil($availableSegments * 1.5), // Rough estimate
                    'started_at' => now()->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error starting course transcription processing', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error starting course transcription processing',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get comprehensive course processing statistics
     */
    public function getCourseProcessingStats(LocalTruefireCourse $truefireCourse, Request $request)
    {
        try {
            // Load course with segments
            $course = $truefireCourse->load(['channels.segments' => function ($query) {
                $query->withVideo();
            }]);

            // Collect all segments
            $allSegments = collect();
            foreach ($course->channels as $channel) {
                $allSegments = $allSegments->merge($channel->segments);
            }

            $totalSegments = $allSegments->count();
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            $disk = 'd_drive';

            // Count audio extraction completion
            $audioExtracted = 0;
            $audioFilesSizes = [];
            foreach ($allSegments as $segment) {
                $audioFilePath = "{$courseDir}/{$segment->id}.wav";
                if (Storage::disk($disk)->exists($audioFilePath)) {
                    $audioExtracted++;
                    $audioFilesSizes[] = Storage::disk($disk)->size($audioFilePath);
                }
            }

            // Count transcription completion
            $transcribed = 0;
            $transcriptionLogs = collect();
            foreach ($allSegments as $segment) {
                $transcriptPath = "{$courseDir}/{$segment->id}_transcript.txt";
                if (Storage::disk($disk)->exists($transcriptPath)) {
                    $transcribed++;
                }

                // Collect transcription logs for this segment
                $logs = TranscriptionLog::where('extraction_settings->segment_id', $segment->id)
                    ->where('extraction_settings->course_id', $truefireCourse->id)
                    ->get();
                $transcriptionLogs = $transcriptionLogs->merge($logs);
            }

            // Calculate quality metrics from transcription logs
            $qualityScores = $transcriptionLogs
                ->where('status', 'completed')
                ->whereNotNull('transcription_confidence_score')
                ->pluck('transcription_confidence_score')
                ->filter(function ($score) {
                    return is_numeric($score) && $score > 0;
                });

            $avgQualityScore = $qualityScores->isNotEmpty() ? $qualityScores->avg() : null;

            // Calculate processing times from transcription logs
            $processingTimes = $transcriptionLogs
                ->where('status', 'completed')
                ->whereNotNull('total_processing_duration_seconds')
                ->pluck('total_processing_duration_seconds')
                ->filter(function ($time) {
                    return is_numeric($time) && $time > 0;
                });

            $avgProcessingTime = $processingTimes->isNotEmpty() ? $processingTimes->avg() : null;
            $totalProcessingTime = $processingTimes->sum();

            // Get recent batch processing jobs
            $recentJobs = TranscriptionLog::where('extraction_settings->course_id', $truefireCourse->id)
                ->where('extraction_settings->batch_processing', true)
                ->where('file_name', 'like', "Course #{$truefireCourse->id}%")
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($log) {
                    return [
                        'job_id' => $log->job_id,
                        'status' => $log->status,
                        'started_at' => $log->started_at,
                        'completed_at' => $log->completed_at,
                        'duration_minutes' => $log->total_processing_duration_seconds ? round($log->total_processing_duration_seconds / 60, 1) : null,
                        'processed_segments' => $log->extraction_settings['processed_segments'] ?? 0,
                        'error_message' => $log->error_message
                    ];
                });

            // Calculate completion percentages
            $audioCompletionPercentage = $totalSegments > 0 ? round(($audioExtracted / $totalSegments) * 100, 1) : 0;
            $transcriptionCompletionPercentage = $totalSegments > 0 ? round(($transcribed / $totalSegments) * 100, 1) : 0;

            // Calculate total audio size
            $totalAudioSizeMB = array_sum($audioFilesSizes) / (1024 * 1024);

            $stats = [
                'course_info' => [
                    'id' => $truefireCourse->id,
                    'title' => $truefireCourse->title ?? "Course #{$truefireCourse->id}",
                    'total_segments' => $totalSegments
                ],
                'audio_extraction' => [
                    'completed_segments' => $audioExtracted,
                    'total_segments' => $totalSegments,
                    'completion_percentage' => $audioCompletionPercentage,
                    'total_audio_size_mb' => round($totalAudioSizeMB, 2),
                    'avg_file_size_mb' => $audioExtracted > 0 ? round($totalAudioSizeMB / $audioExtracted, 2) : 0
                ],
                'transcription' => [
                    'completed_segments' => $transcribed,
                    'total_segments' => $totalSegments,
                    'completion_percentage' => $transcriptionCompletionPercentage,
                    'avg_quality_score' => $avgQualityScore ? round($avgQualityScore, 3) : null,
                    'total_processing_time_minutes' => $totalProcessingTime > 0 ? round($totalProcessingTime / 60, 1) : null,
                    'avg_processing_time_seconds' => $avgProcessingTime ? round($avgProcessingTime, 1) : null
                ],
                'recent_batch_jobs' => $recentJobs->toArray(),
                'summary' => [
                    'overall_completion_percentage' => round((($audioExtracted + $transcribed) / ($totalSegments * 2)) * 100, 1),
                    'ready_for_transcription' => $audioExtracted - $transcribed,
                    'total_processing_time_hours' => $totalProcessingTime > 0 ? round($totalProcessingTime / 3600, 2) : null,
                    'estimated_remaining_time_minutes' => ($audioExtracted - $transcribed > 0 && $avgProcessingTime) 
                        ? round((($audioExtracted - $transcribed) * $avgProcessingTime) / 60, 1) 
                        : null
                ],
                'updated_at' => now()->toISOString()
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting course processing statistics', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error getting course processing statistics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Restart course transcription processing
     */
    public function restartCourseTranscription(LocalTruefireCourse $truefireCourse, Request $request)
    {
        try {
            $validated = $request->validate([
                'preset' => 'sometimes|string|in:fast,balanced,high,premium',
                'clear_existing' => 'sometimes|boolean',
                'segments_only' => 'sometimes|array',
                'segments_only.*' => 'integer'
            ]);

            $preset = $validated['preset'] ?? $truefireCourse->getTranscriptionPreset();
            $clearExisting = $validated['clear_existing'] ?? false;
            $segmentsOnly = $validated['segments_only'] ?? null;

            // If clearing existing transcriptions, remove transcript files
            if ($clearExisting) {
                $courseDir = "truefire-courses/{$truefireCourse->id}";
                $disk = 'd_drive';

                // Get segments to clear
                $segmentsToClear = $segmentsOnly ?? $truefireCourse->segments->pluck('id')->toArray();

                $clearedCount = 0;
                $preservedSourceFiles = 0;
                
                foreach ($segmentsToClear as $segmentId) {
                    // SAFETY: Only delete transcription files - NEVER source MP4 files
                    $transcriptFilesToDelete = [
                        "{$courseDir}/{$segmentId}_transcript.txt",
                        "{$courseDir}/{$segmentId}_transcript.srt",
                        "{$courseDir}/{$segmentId}_transcript.json"
                    ];

                    // SAFETY CHECK: Verify source MP4 file is preserved
                    $sourceVideoFile = "{$courseDir}/{$segmentId}.mp4";
                    if (Storage::disk($disk)->exists($sourceVideoFile)) {
                        $preservedSourceFiles++;
                    }

                    // Only delete transcript files (never audio or video)
                    foreach ($transcriptFilesToDelete as $filePath) {
                        // SAFETY: Double-check we're not deleting source files
                        if (str_ends_with($filePath, '.mp4')) {
                            Log::error('SAFETY VIOLATION: Attempted to delete source MP4 file', [
                                'file_path' => $filePath,
                                'course_id' => $truefireCourse->id,
                                'segment_id' => $segmentId
                            ]);
                            throw new \Exception("Safety violation: Cannot delete source MP4 files");
                        }

                        if (Storage::disk($disk)->exists($filePath)) {
                            Storage::disk($disk)->delete($filePath);
                            $clearedCount++;
                        }
                    }
                }

                // Clear transcription logs for restarted segments
                TranscriptionLog::where('extraction_settings->course_id', $truefireCourse->id)
                    ->when($segmentsOnly, function ($query) use ($segmentsOnly) {
                        return $query->whereIn('extraction_settings->segment_id', $segmentsOnly);
                    })
                    ->where('status', '!=', 'processing') // Don't clear currently processing jobs
                    ->update([
                        'status' => 'cancelled',
                        'error_message' => 'Cleared for restart',
                        'completed_at' => now()
                    ]);

                Log::info('Cleared existing transcriptions for restart', [
                    'course_id' => $truefireCourse->id,
                    'cleared_transcript_files' => $clearedCount,
                    'preserved_source_mp4_files' => $preservedSourceFiles,
                    'segments_processed' => $segmentsOnly ? count($segmentsOnly) : 'all',
                    'safety_check' => 'Source MP4 files preserved',
                    'user_id' => Auth::id()
                ]);
            }

            // Start fresh transcription processing
            $response = $this->processAllTranscriptions($truefireCourse, new Request([
                'force_restart' => true,
                'preset' => $preset,
                'settings' => [
                    'restart_operation' => true,
                    'cleared_existing' => $clearExisting,
                    'segments_filter' => $segmentsOnly
                ]
            ]));

            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getContent(), true);
                $responseData['message'] = 'Course transcription restarted successfully';
                $responseData['data']['restart_operation'] = true;
                $responseData['data']['cleared_existing'] = $clearExisting;

                return response()->json($responseData);
            }

            return $response;

        } catch (\Exception $e) {
            Log::error('Error restarting course transcription', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error restarting course transcription',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Restart course audio extraction processing
     */
    public function restartCourseAudioExtraction(LocalTruefireCourse $truefireCourse, Request $request)
    {
        try {
            $validated = $request->validate([
                'preset' => 'sometimes|string|in:low,medium,high,premium',
                'clear_existing' => 'sometimes|boolean',
                'segments_only' => 'sometimes|array',
                'segments_only.*' => 'integer'
            ]);

            $preset = $validated['preset'] ?? $truefireCourse->getAudioExtractionPreset();
            $clearExisting = $validated['clear_existing'] ?? false;
            $segmentsOnly = $validated['segments_only'] ?? null;

            // If clearing existing audio files, remove them
            if ($clearExisting) {
                $courseDir = "truefire-courses/{$truefireCourse->id}";
                $disk = 'd_drive';

                // Load course with segments to clear
                $course = $truefireCourse->load(['channels.segments' => function ($query) {
                    $query->withVideo();
                }]);

                $allSegments = collect();
                foreach ($course->channels as $channel) {
                    $allSegments = $allSegments->merge($channel->segments);
                }

                $segmentsToClear = $segmentsOnly ?? $allSegments->pluck('id')->toArray();

                $clearedCount = 0;
                $preservedSourceFiles = 0;
                
                foreach ($segmentsToClear as $segmentId) {
                    // SAFETY: Only delete processed audio files - NEVER source MP4 files
                    $audioFilesToDelete = [
                        "{$courseDir}/{$segmentId}.wav",
                        "{$courseDir}/{$segmentId}.mp3"
                    ];

                    // SAFETY CHECK: Verify source MP4 file is preserved
                    $sourceVideoFile = "{$courseDir}/{$segmentId}.mp4";
                    if (Storage::disk($disk)->exists($sourceVideoFile)) {
                        $preservedSourceFiles++;
                    }

                    // Only delete processed audio files (never source video files)
                    foreach ($audioFilesToDelete as $filePath) {
                        // SAFETY: Double-check we're not deleting source files
                        if (str_ends_with($filePath, '.mp4')) {
                            Log::error('SAFETY VIOLATION: Attempted to delete source MP4 file', [
                                'file_path' => $filePath,
                                'course_id' => $truefireCourse->id,
                                'segment_id' => $segmentId
                            ]);
                            throw new \Exception("Safety violation: Cannot delete source MP4 files");
                        }

                        if (Storage::disk($disk)->exists($filePath)) {
                            Storage::disk($disk)->delete($filePath);
                            $clearedCount++;
                        }
                    }
                }

                // Clear audio extraction logs for restarted segments
                TranscriptionLog::where('extraction_settings->course_id', $truefireCourse->id)
                    ->where('is_test_extraction', true) // Audio extraction logs
                    ->when($segmentsOnly, function ($query) use ($segmentsOnly) {
                        return $query->whereIn('extraction_settings->segment_id', $segmentsOnly);
                    })
                    ->where('status', '!=', 'processing') // Don't clear currently processing jobs
                    ->update([
                        'status' => 'cancelled',
                        'error_message' => 'Cleared for restart',
                        'completed_at' => now()
                    ]);

                Log::info('Cleared existing audio files for restart', [
                    'course_id' => $truefireCourse->id,
                    'cleared_audio_files' => $clearedCount,
                    'preserved_source_mp4_files' => $preservedSourceFiles,
                    'segments_processed' => $segmentsOnly ? count($segmentsOnly) : 'all',
                    'safety_check' => 'Source MP4 files preserved',
                    'user_id' => Auth::id()
                ]);
            }

            // Start fresh audio extraction processing
            $response = $this->processAllVideos($truefireCourse, new Request([
                'force_restart' => true,
                'preset' => $preset,
                'settings' => [
                    'restart_operation' => true,
                    'cleared_existing' => $clearExisting,
                    'segments_filter' => $segmentsOnly
                ]
            ]));

            if ($response->getStatusCode() === 200) {
                $responseData = json_decode($response->getContent(), true);
                $responseData['message'] = 'Course audio extraction restarted successfully';
                $responseData['data']['restart_operation'] = true;
                $responseData['data']['cleared_existing'] = $clearExisting;

                return response()->json($responseData);
            }

            return $response;

        } catch (\Exception $e) {
            Log::error('Error restarting course audio extraction', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error restarting course audio extraction',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Restart entire course processing pipeline (audio extraction + transcription)
     */
    public function restartEntireCourseProcessing(LocalTruefireCourse $truefireCourse, Request $request)
    {
        try {
            $validated = $request->validate([
                'audio_preset' => 'sometimes|string|in:low,medium,high,premium',
                'transcription_preset' => 'sometimes|string|in:fast,balanced,high,premium',
                'clear_existing' => 'sometimes|boolean',
                'segments_only' => 'sometimes|array',
                'segments_only.*' => 'integer'
            ]);

            $audioPreset = $validated['audio_preset'] ?? $truefireCourse->getAudioExtractionPreset();
            $transcriptionPreset = $validated['transcription_preset'] ?? $truefireCourse->getTranscriptionPreset();
            $clearExisting = $validated['clear_existing'] ?? false;
            $segmentsOnly = $validated['segments_only'] ?? null;

            // If clearing existing files, remove both audio and transcription files
            if ($clearExisting) {
                $courseDir = "truefire-courses/{$truefireCourse->id}";
                $disk = 'd_drive';

                // Load course with segments to clear
                $course = $truefireCourse->load(['channels.segments' => function ($query) {
                    $query->withVideo();
                }]);

                $allSegments = collect();
                foreach ($course->channels as $channel) {
                    $allSegments = $allSegments->merge($channel->segments);
                }

                $segmentsToClear = $segmentsOnly ?? $allSegments->pluck('id')->toArray();

                $clearedCount = 0;
                $preservedSourceFiles = 0;
                
                foreach ($segmentsToClear as $segmentId) {
                    // SAFETY: Only delete processed files - NEVER source MP4 files
                    $filesToDelete = [
                        // Processed audio files only
                        "{$courseDir}/{$segmentId}.wav",
                        "{$courseDir}/{$segmentId}.mp3",
                        // Transcription files only
                        "{$courseDir}/{$segmentId}_transcript.txt",
                        "{$courseDir}/{$segmentId}_transcript.srt",
                        "{$courseDir}/{$segmentId}_transcript.json"
                    ];

                    // SAFETY CHECK: Verify source MP4 file is preserved
                    $sourceVideoFile = "{$courseDir}/{$segmentId}.mp4";
                    if (Storage::disk($disk)->exists($sourceVideoFile)) {
                        $preservedSourceFiles++;
                    }

                    // Only delete processed files (never source video files)
                    foreach ($filesToDelete as $filePath) {
                        // SAFETY: Double-check we're not deleting source files
                        if (str_ends_with($filePath, '.mp4')) {
                            Log::error('SAFETY VIOLATION: Attempted to delete source MP4 file', [
                                'file_path' => $filePath,
                                'course_id' => $truefireCourse->id,
                                'segment_id' => $segmentId
                            ]);
                            throw new \Exception("Safety violation: Cannot delete source MP4 files");
                        }

                        if (Storage::disk($disk)->exists($filePath)) {
                            Storage::disk($disk)->delete($filePath);
                            $clearedCount++;
                        }
                    }
                }

                // Clear all processing logs for restarted segments
                TranscriptionLog::where('extraction_settings->course_id', $truefireCourse->id)
                    ->when($segmentsOnly, function ($query) use ($segmentsOnly) {
                        return $query->whereIn('extraction_settings->segment_id', $segmentsOnly);
                    })
                    ->where('status', '!=', 'processing') // Don't clear currently processing jobs
                    ->update([
                        'status' => 'cancelled',
                        'error_message' => 'Cleared for complete restart',
                        'completed_at' => now()
                    ]);

                Log::info('Cleared all existing processed files for complete course restart', [
                    'course_id' => $truefireCourse->id,
                    'cleared_processed_files' => $clearedCount,
                    'preserved_source_mp4_files' => $preservedSourceFiles,
                    'segments_processed' => $segmentsOnly ? count($segmentsOnly) : 'all',
                    'audio_preset' => $audioPreset,
                    'transcription_preset' => $transcriptionPreset,
                    'safety_check' => 'Source MP4 files preserved',
                    'user_id' => Auth::id()
                ]);
            }

            // Start fresh audio extraction first (transcription will follow once audio is ready)
            $audioResponse = $this->processAllVideos($truefireCourse, new Request([
                'force_restart' => true,
                'preset' => $audioPreset,
                'settings' => [
                    'restart_operation' => true,
                    'complete_pipeline_restart' => true,
                    'cleared_existing' => $clearExisting,
                    'segments_filter' => $segmentsOnly,
                    'follow_with_transcription' => true,
                    'transcription_preset' => $transcriptionPreset
                ]
            ]));

            if ($audioResponse->getStatusCode() === 200) {
                $audioData = json_decode($audioResponse->getContent(), true);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Complete course processing pipeline restarted successfully',
                    'data' => [
                        'restart_type' => 'complete_pipeline',
                        'audio_processing' => $audioData['data'] ?? [],
                        'transcription_preset' => $transcriptionPreset,
                        'cleared_existing' => $clearExisting,
                        'pipeline_stages' => [
                            '1_audio_extraction' => 'started',
                            '2_transcription' => 'will_follow_audio_completion'
                        ],
                        'estimated_total_duration_minutes' => ($audioData['data']['estimated_duration_minutes'] ?? 0) * 2, // Rough estimate for both stages
                        'course_id' => $truefireCourse->id,
                        'started_at' => now()->toISOString()
                    ]
                ]);
            }

            return $audioResponse;

        } catch (\Exception $e) {
            Log::error('Error restarting entire course processing', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error restarting complete course processing',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}