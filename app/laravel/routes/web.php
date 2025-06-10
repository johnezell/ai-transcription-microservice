<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\TruefireCourseController;
use App\Http\Controllers\BatchTestController;
use App\Http\Controllers\EnhancementIdeaController;
use App\Http\Controllers\DownloadMonitorController;
use App\Http\Controllers\JobsController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return redirect()->route('truefire-courses.index');
});

Route::get('/dashboard', function () {
    return redirect()->route('truefire-courses.index');
})->name('dashboard');

// Video management routes - no auth required
Route::resource('videos', VideoController::class);
Route::post('/videos/{video}/transcription', [VideoController::class, 'requestTranscription'])
    ->name('videos.transcription.request');
Route::post('/videos/{video}/approve-audio-extraction', [VideoController::class, 'approveAudioExtraction'])
    ->name('videos.audio-extraction.approve');

// Course management routes
Route::resource('courses', \App\Http\Controllers\CourseController::class);

// TrueFire Course management routes
// Bulk download routes for ALL courses - MUST come before resource routes
Route::get('/truefire-courses/download-all-courses', [TruefireCourseController::class, 'downloadAllCourses'])
    ->name('truefire-courses.download-all-courses');
Route::get('/truefire-courses/bulk-download-status', [TruefireCourseController::class, 'bulkDownloadStatus'])
    ->name('truefire-courses.bulk-download-status');
Route::get('/truefire-courses/bulk-queue-status', [TruefireCourseController::class, 'bulkQueueStatus'])
    ->name('truefire-courses.bulk-queue-status');
Route::get('/truefire-courses/bulk-download-stats', [TruefireCourseController::class, 'bulkDownloadStats'])
    ->name('truefire-courses.bulk-download-stats');

// Cache management routes - also need to be before resource routes
Route::post('/truefire-courses/clear-cache', [TruefireCourseController::class, 'clearAllCaches'])
    ->name('truefire-courses.clear-cache');
Route::post('/truefire-courses/warm-cache', [TruefireCourseController::class, 'warmCache'])
    ->name('truefire-courses.warm-cache');

// Resource route - comes after specific routes to avoid conflicts
Route::resource('truefire-courses', TruefireCourseController::class)->only(['index', 'show']);

// Individual course download routes - these use {truefireCourse} parameter
Route::get('/truefire-courses/{truefireCourse}/download-all', [TruefireCourseController::class, 'downloadAll'])
    ->name('truefire-courses.download-all');
Route::get('/truefire-courses/{truefireCourse}/download-status', [TruefireCourseController::class, 'downloadStatus'])
    ->name('truefire-courses.download-status');
Route::get('/truefire-courses/{truefireCourse}/download-stats', [TruefireCourseController::class, 'downloadStats'])
    ->name('truefire-courses.download-stats');
Route::get('/truefire-courses/{truefireCourse}/queue-status', [TruefireCourseController::class, 'queueStatus'])
    ->name('truefire-courses.queue-status');

// TrueFire Course Segment Viewer routes - NEW functionality for video transcription testing
Route::get('/truefire-courses/{truefireCourse}/segments/{segment}', [TruefireCourseController::class, 'showSegment'])
    ->name('truefire-courses.segments.show');
Route::post('/truefire-courses/{truefireCourse}/segments/{segment}/transcription', [TruefireCourseController::class, 'requestSegmentTranscription'])
    ->name('truefire-courses.segments.transcription.request');
Route::post('/truefire-courses/{truefireCourse}/segments/{segment}/transcription/restart', [TruefireCourseController::class, 'restartSegmentTranscription'])
    ->name('truefire-courses.segments.transcription.restart');
Route::get('/truefire-courses/{truefireCourse}/segments/{segment}/video', [TruefireCourseController::class, 'serveSegmentVideo'])
    ->name('truefire-courses.segment.video');
Route::get('/truefire-courses/{course}/segments/{segment}/audio/{filename}', [TruefireCourseController::class, 'serveSegmentAudio'])
    ->name('truefire-courses.segments.audio');
Route::post('/truefire-courses/{truefireCourse}/segments/{segment}/approve-audio-extraction', [TruefireCourseController::class, 'approveSegmentAudioExtraction'])
    ->name('truefire-courses.segments.audio-extraction.approve');
Route::post('/truefire-courses/{truefireCourse}/segments/{segment}/terminology', [TruefireCourseController::class, 'requestSegmentTerminology'])
    ->name('truefire-courses.segments.terminology.request');
Route::post('/courses/{course}/videos', [\App\Http\Controllers\CourseController::class, 'addVideo'])
    ->name('courses.videos.add');
Route::delete('/courses/{course}/videos', [\App\Http\Controllers\CourseController::class, 'removeVideo'])
    ->name('courses.videos.remove');
Route::put('/courses/{course}/videos/order', [\App\Http\Controllers\CourseController::class, 'updateVideoOrder'])
    ->name('courses.videos.order');
Route::get('/courses/{course}/analysis', [\App\Http\Controllers\CourseController::class, 'analysis'])
    ->name('courses.analysis');
Route::delete('/courses/{course}/destroy-with-videos', [\App\Http\Controllers\CourseController::class, 'destroyWithVideos'])
    ->name('courses.destroy-with-videos');
Route::post('/truefire-courses/{truefireCourse}/download-segment/{segmentId}', [TruefireCourseController::class, 'downloadSegment'])
    ->name('truefire-courses.download-segment');

// Audio extraction testing routes
Route::post('/truefire-courses/{truefireCourse}/test-audio-extraction/{segmentId}', [TruefireCourseController::class, 'testAudioExtraction'])
    ->name('truefire-courses.test-audio-extraction');
Route::get('/truefire-courses/{truefireCourse}/audio-test-results/{segmentId}', [TruefireCourseController::class, 'getAudioTestResults'])
    ->name('truefire-courses.audio-test-results');
Route::get('/audio-test-history', [TruefireCourseController::class, 'getAudioTestHistory'])
    ->name('audio-test-history');

// Batch audio extraction testing routes
Route::post('/truefire-courses/{truefireCourse}/create-batch-test', [TruefireCourseController::class, 'createBatchTest'])
    ->name('truefire-courses.create-batch-test');
Route::get('/truefire-courses/{truefireCourse}/batch-test/{batchId}/status', [TruefireCourseController::class, 'getBatchTestStatus'])
    ->name('truefire-courses.batch-test-status');
Route::get('/truefire-courses/{truefireCourse}/batch-test/{batchId}/results', [TruefireCourseController::class, 'getBatchTestResults'])
    ->name('truefire-courses.batch-test-results');
Route::post('/truefire-courses/{truefireCourse}/batch-test/{batchId}/cancel', [TruefireCourseController::class, 'cancelBatchTest'])
    ->name('truefire-courses.cancel-batch-test');
Route::post('/truefire-courses/{truefireCourse}/batch-test/{batchId}/retry', [TruefireCourseController::class, 'retryBatchTest'])
    ->name('truefire-courses.retry-batch-test');
Route::delete('/truefire-courses/{truefireCourse}/batch-test/{batchId}', [TruefireCourseController::class, 'deleteBatchTest'])
    ->name('truefire-courses.delete-batch-test');

// Course-level audio extraction preset and batch processing routes
Route::put('/truefire-courses/{truefireCourse}/audio-preset', [TruefireCourseController::class, 'setAudioPreset'])
    ->name('truefire-courses.set-audio-preset');
Route::get('/truefire-courses/{truefireCourse}/audio-preset', [TruefireCourseController::class, 'getAudioPreset'])
    ->name('truefire-courses.get-audio-preset');
Route::post('/truefire-courses/{truefireCourse}/process-all-videos', [TruefireCourseController::class, 'processAllVideos'])
    ->name('truefire-courses.process-all-videos');
Route::get('/truefire-courses/{truefireCourse}/audio-extraction-progress', [TruefireCourseController::class, 'getCourseAudioExtractionProgress'])
    ->name('truefire-courses.audio-extraction-progress');

// Course audio extraction batch processing routes
Route::post('/truefire-courses/{truefireCourse}/process-all-audio-extractions', [TruefireCourseController::class, 'processAllAudioExtractions'])
    ->name('truefire-courses.process-all-audio-extractions');

// Course transcription batch processing routes
Route::post('/truefire-courses/{truefireCourse}/process-all-transcriptions', [TruefireCourseController::class, 'processAllTranscriptions'])
    ->name('truefire-courses.process-all-transcriptions');
Route::post('/truefire-courses/{truefireCourse}/restart-transcription', [TruefireCourseController::class, 'restartCourseTranscription'])
    ->name('truefire-courses.restart-transcription');
Route::get('/truefire-courses/{truefireCourse}/processing-stats', [TruefireCourseController::class, 'getCourseProcessingStats'])
    ->name('truefire-courses.processing-stats');

// Course restart processing routes
Route::post('/truefire-courses/{truefireCourse}/restart-audio-extraction', [TruefireCourseController::class, 'restartCourseAudioExtraction'])
    ->name('truefire-courses.restart-audio-extraction');
Route::post('/truefire-courses/{truefireCourse}/restart-entire-processing', [TruefireCourseController::class, 'restartEntireCourseProcessing'])
    ->name('truefire-courses.restart-entire-processing');

// Global batch management routes
Route::get('/batch-tests', [BatchTestController::class, 'index'])
    ->name('batch-tests.index');
Route::post('/batch-tests', [BatchTestController::class, 'store'])
    ->name('batch-tests.store');
Route::get('/batch-tests/{batch}', [BatchTestController::class, 'show'])
    ->name('batch-tests.show');
Route::patch('/batch-tests/{batch}', [BatchTestController::class, 'update'])
    ->name('batch-tests.update');
Route::post('/batch-tests/{batch}/cancel', [BatchTestController::class, 'cancel'])
    ->name('batch-tests.cancel');
Route::post('/batch-tests/{batch}/retry', [BatchTestController::class, 'retry'])
    ->name('batch-tests.retry');
Route::delete('/batch-tests/{batch}', [BatchTestController::class, 'destroy'])
    ->name('batch-tests.destroy');
Route::post('/batch-tests/{batch}/export', [BatchTestController::class, 'export'])
    ->name('batch-tests.export');
Route::get('/batch-tests-statistics', [BatchTestController::class, 'statistics'])
    ->name('batch-tests.statistics');

// Audio Test Monitoring Routes
Route::prefix('audio-test-monitoring')->name('audio-test-monitoring.')->group(function () {
    Route::get('/system-metrics', [App\Http\Controllers\AudioTestMonitoringController::class, 'getSystemMetrics'])
        ->name('system-metrics');
    Route::get('/processing-stats', [App\Http\Controllers\AudioTestMonitoringController::class, 'getProcessingStats'])
        ->name('processing-stats');
    Route::get('/queue-status', [App\Http\Controllers\AudioTestMonitoringController::class, 'getQueueStatus'])
        ->name('queue-status');
    Route::get('/user-activity', [App\Http\Controllers\AudioTestMonitoringController::class, 'getUserActivity'])
        ->name('user-activity');
    Route::get('/performance-trends', [App\Http\Controllers\AudioTestMonitoringController::class, 'getPerformanceTrends'])
        ->name('performance-trends');
    Route::get('/resource-usage', [App\Http\Controllers\AudioTestMonitoringController::class, 'getResourceUsage'])
        ->name('resource-usage');
    Route::get('/alerts', [App\Http\Controllers\AudioTestMonitoringController::class, 'getAlerts'])
        ->name('alerts');
});

// Terminology Management (admin routes)
Route::prefix('admin')->name('admin.')->group(function () {
    // Main terminology management page
    Route::get('/terminology', [App\Http\Controllers\Admin\TerminologyController::class, 'index'])
        ->name('terminology.index');
    
    // Category management
    Route::get('/terminology/categories/create', [App\Http\Controllers\Admin\TerminologyController::class, 'createCategory'])
        ->name('terminology.categories.create');
    Route::post('/terminology/categories', [App\Http\Controllers\Admin\TerminologyController::class, 'storeCategory'])
        ->name('terminology.categories.store');
    Route::get('/terminology/categories/{id}/edit', [App\Http\Controllers\Admin\TerminologyController::class, 'editCategory'])
        ->name('terminology.categories.edit');
    Route::put('/terminology/categories/{id}', [App\Http\Controllers\Admin\TerminologyController::class, 'updateCategory'])
        ->name('terminology.categories.update');
    Route::delete('/terminology/categories/{id}', [App\Http\Controllers\Admin\TerminologyController::class, 'destroyCategory'])
        ->name('terminology.categories.destroy');
    
    // Term management
    Route::get('/terminology/terms/create', [App\Http\Controllers\Admin\TerminologyController::class, 'createTerm'])
        ->name('terminology.terms.create');
    Route::post('/terminology/terms', [App\Http\Controllers\Admin\TerminologyController::class, 'storeTerm'])
        ->name('terminology.terms.store');
    Route::put('/terminology/terms/{id}', [App\Http\Controllers\Admin\TerminologyController::class, 'updateTerm'])
        ->name('terminology.terms.update');
    Route::delete('/terminology/terms/{id}', [App\Http\Controllers\Admin\TerminologyController::class, 'destroyTerm'])
        ->name('terminology.terms.destroy');
    
    // Import/Export
    Route::get('/terminology/export', [App\Http\Controllers\Admin\TerminologyController::class, 'export'])
        ->name('terminology.export');
    Route::get('/terminology/import', [App\Http\Controllers\Admin\TerminologyController::class, 'importForm'])
        ->name('terminology.import');
    Route::post('/terminology/import', [App\Http\Controllers\Admin\TerminologyController::class, 'import'])
        ->name('terminology.import.process');
    
    // Keep the old routes for backward compatibility during transition
    Route::get('/music-terms', function() {
        return redirect()->route('admin.terminology.index');
    })->name('music-terms.index');
});

Route::get('/fix-statuses', function () {
    // Find all videos that are in 'transcribed' state but have terminology data
    $videos = \App\Models\Video::where('status', 'transcribed')
        ->where(function($query) {
            $query->whereNotNull('terminology_path')
                  ->orWhereNotNull('music_terms_path')
                  ->orWhere('has_terminology', true)
                  ->orWhere('has_music_terms', true);
        })
        ->get();
    
    $updated = 0;
    $details = [];
    
    foreach ($videos as $video) {
        $details[] = [
            'id' => $video->id,
            'original_filename' => $video->original_filename,
            'old_status' => $video->status,
            'has_terminology' => $video->has_terminology,
            'has_music_terms' => $video->has_music_terms,
        ];
        
        $video->update(['status' => 'completed']);
        $updated++;
    }
    
    return response()->json([
        'success' => true,
        'videos_found' => $videos->count(),
        'videos_updated' => $updated,
        'details' => $details
    ]);
})->name('fix.statuses');

// Remove from auth middleware group
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Jobs visualization route - protected by auth
    Route::get('/jobs', [JobsController::class, 'index'])->name('jobs.index');
    
    // Job management routes - protected by auth
    Route::post('/jobs/prune-all', [JobsController::class, 'pruneAll'])->name('jobs.prune-all');
    Route::post('/jobs/clear-failed', [JobsController::class, 'clearFailed'])->name('jobs.clear-failed');
    
    // Enhancement Ideas routes - protected by auth
    Route::prefix('enhancement-ideas')->name('enhancement-ideas.')->group(function () {
        Route::get('/', [EnhancementIdeaController::class, 'index'])->name('index');
        Route::post('/', [EnhancementIdeaController::class, 'store'])->name('store');
        Route::get('/{enhancementIdea}', [EnhancementIdeaController::class, 'show'])->name('show');
        Route::put('/{enhancementIdea}', [EnhancementIdeaController::class, 'update'])->name('update');
        Route::delete('/{enhancementIdea}', [EnhancementIdeaController::class, 'destroy'])->name('destroy');
        Route::post('/{enhancementIdea}/comments', [EnhancementIdeaController::class, 'addComment'])->name('comments.store');
        Route::post('/{enhancementIdea}/toggle-complete', [EnhancementIdeaController::class, 'toggleComplete'])->name('toggle-complete');
    });
    
    // Utility routes
    Route::get('/test-terminology/{id}', function ($id) {
        // Find the video
        $video = \App\Models\Video::findOrFail($id);
        
        // Create a controller instance
        $controller = new \App\Http\Controllers\Api\TerminologyController();
        
        // Call the process method
        $response = $controller->process(new \Illuminate\Http\Request(), $id);
        
        // Return the response
        return $response;
    })->name('test.terminology');
    
    Route::get('/fix-terminology', function () {
        // Find all videos in 'transcribed' state
        $videos = \App\Models\Video::where('status', 'transcribed')->get();
        
        $output = [];
        $count = 0;
        
        // For each video, trigger terminology recognition
        foreach ($videos as $video) {
            // Create a controller instance
            $controller = new \App\Http\Controllers\Api\TerminologyController();
            
            // Call the process method
            $response = $controller->process(new \Illuminate\Http\Request(), $video->id);
            
            // Process the response
            $responseData = json_decode($response->getContent(), true);
            $success = $responseData['success'] ?? false;
            
            $output[] = [
                'id' => $video->id,
                'name' => $video->original_filename,
                'success' => $success,
                'message' => $responseData['message'] ?? 'Unknown response'
            ];
            
            if ($success) {
                $count++;
            }
        }
        
        return response()->json([
            'total_videos' => $videos->count(),
            'processed_successfully' => $count,
            'results' => $output
        ]);
    })->name('fix.terminology');
});

// Add back outside of auth
// Enhancement Ideas routes
Route::prefix('enhancement-ideas')->name('enhancement-ideas.')->group(function () {
    Route::get('/', [EnhancementIdeaController::class, 'index'])->name('index');
    Route::post('/', [EnhancementIdeaController::class, 'store'])->name('store');
    Route::get('/{enhancementIdea}', [EnhancementIdeaController::class, 'show'])->name('show');
    Route::put('/{enhancementIdea}', [EnhancementIdeaController::class, 'update'])->name('update');
    Route::delete('/{enhancementIdea}', [EnhancementIdeaController::class, 'destroy'])->name('destroy');
    Route::post('/{enhancementIdea}/comments', [EnhancementIdeaController::class, 'addComment'])->name('comments.store');
    Route::post('/{enhancementIdea}/toggle-complete', [EnhancementIdeaController::class, 'toggleComplete'])->name('toggle-complete');
});

Route::get('/test-audio-job/{id}', function ($id) {
    $video = App\Models\Video::find($id);
    
    if (!$video) {
        return response()->json(['error' => 'Video not found'], 404);
    }
    
    try {
        App\Jobs\AudioExtractionJob::dispatch($video);
        return response()->json([
            'success' => true,
            'message' => 'Audio extraction job dispatched for video ' . $id,
            'video' => $video
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage()
        ], 500);
    }
});

Route::get('/fix-status-immediate/{id}', function ($id) {
    try {
        // Find the video
        $video = \App\Models\Video::findOrFail($id);
        
        // Force update the status to completed immediately
        $video->update([
            'status' => 'completed'
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Video status force-updated to completed',
            'video_id' => $id,
            'old_status' => $video->getOriginal('status'),
            'new_status' => 'completed'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error updating video status',
            'error' => $e->getMessage()
        ], 500);
    }
});

// CloudFront testing interface
Route::get('/cloudfront-test', function () {
    return view('cloudfront-test');
})->name('cloudfront.test');

// Test S3 connectivity
Route::get('/test-s3', function () {
    try {
        $s3Service = new \App\Services\S3Service();
        
        // Test by listing files (this will verify credentials work)
        $files = $s3Service->listFiles();
        
        return response()->json([
            'status' => 'success',
            'message' => 'S3 connection successful!',
            'files_count' => count($files),
            'bucket' => config('filesystems.disks.s3.bucket'),
            'region' => config('filesystems.disks.s3.region'),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'S3 connection failed: ' . $e->getMessage(),
        ], 500);
    }
})->name('test.s3');

// Test CloudFront Segment Signed URLs
Route::get('/test-segment-url/{segmentId?}', function ($segmentId = null) {
    try {
        // Get a segment for testing
        $segment = $segmentId 
            ? \App\Models\Segment::find($segmentId)
            : \App\Models\Segment::with('channel')->first();
        
        if (!$segment) {
            return response()->json([
                'status' => 'error',
                'message' => 'No segment found. Please provide a valid segment ID or ensure segments exist.',
            ], 404);
        }

        // Test all URL methods
        $urls = [
            'signed_url_1h' => $segment->getSignedUrl(3600, false),
            'signed_url_5m' => $segment->getSignedUrl(300, false),
            'signed_url_with_ip' => $segment->getSignedUrl(3600, true),
            'unsigned_url' => $segment->getUnsignedUrl(),
            's3_url' => $segment->getS3Url(),
            'multiple_urls' => $segment->getSignedUrls(),
        ];

        return response()->json([
            'status' => 'success',
            'segment_id' => $segment->id,
            'filename' => $segment->filename,
            'channel_slug' => $segment->channel->slug ?? 'unknown',
            'urls' => $urls,
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'CloudFront URL generation failed: ' . $e->getMessage(),
            'trace' => config('app.debug') ? $e->getTraceAsString() : null,
        ], 500);
    }
})->name('test.segment.urls');

// Download monitoring routes
Route::get('/download-monitor', [DownloadMonitorController::class, 'index'])->name('download.monitor');
Route::get('/api/download-stats', [DownloadMonitorController::class, 'stats'])->name('download.stats');

// Test Redis cache tagging
Route::get('/test-cache-tags', function () {
    try {
        // Test cache tagging
        Cache::tags(['test_tag'])->put('test_key', 'test_value', 60);
        $value = Cache::tags(['test_tag'])->get('test_key');
        
        if ($value === 'test_value') {
            // Clean up
            Cache::tags(['test_tag'])->flush();
            
            return response()->json([
                'success' => true,
                'message' => 'Cache tagging is working properly with Redis',
                'cache_driver' => config('cache.default'),
                'value_retrieved' => $value
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Cache tagging test failed - value mismatch',
                'cache_driver' => config('cache.default'),
                'expected' => 'test_value',
                'actual' => $value
            ], 500);
        }
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Cache tagging not supported or Redis not available',
            'cache_driver' => config('cache.default'),
            'error' => $e->getMessage()
        ], 500);
    }
})->name('test.cache.tags');



require __DIR__.'/auth.php';
