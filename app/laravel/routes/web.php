<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\TruefireCourseController;
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
    return redirect()->route('videos.index');
});

Route::get('/dashboard', function () {
    return redirect()->route('videos.index');
})->name('dashboard');

// Video management routes - no auth required
Route::resource('videos', VideoController::class);
Route::post('/videos/{video}/transcription', [VideoController::class, 'requestTranscription'])
    ->name('videos.transcription.request');

// Course management routes
Route::resource('courses', \App\Http\Controllers\CourseController::class);

// TrueFire Course management routes
Route::resource('truefire-courses', TruefireCourseController::class)->only(['index', 'show']);
Route::get('/truefire-courses/{truefireCourse}/download-all', [TruefireCourseController::class, 'downloadAll'])
    ->name('truefire-courses.download-all');
Route::get('/truefire-courses/{truefireCourse}/download-status', [TruefireCourseController::class, 'downloadStatus'])
    ->name('truefire-courses.download-status');
Route::get('/truefire-courses/{truefireCourse}/download-stats', [TruefireCourseController::class, 'downloadStats'])
    ->name('truefire-courses.download-stats');
Route::get('/truefire-courses/{truefireCourse}/queue-status', [TruefireCourseController::class, 'queueStatus'])
    ->name('truefire-courses.queue-status');
Route::post('/truefire-courses/clear-cache', [TruefireCourseController::class, 'clearAllCaches'])
    ->name('truefire-courses.clear-cache');
Route::post('/truefire-courses/warm-cache', [TruefireCourseController::class, 'warmCache'])
    ->name('truefire-courses.warm-cache');
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
