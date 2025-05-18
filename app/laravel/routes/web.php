<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\EnhancementIdeaController;
use App\Http\Controllers\DashboardController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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
    return redirect()->route('dashboard');
});

// Dashboard route - placed at the top for priority
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

// UI Design System
Route::get('/design/buttons', function() {
    return Inertia::render('ButtonStylesDemo');
})->name('design.buttons');

// Video management routes - no auth required
Route::resource('videos', VideoController::class);
Route::post('/videos/{video}/transcription', [VideoController::class, 'requestTranscription'])
    ->name('videos.transcription.request');
Route::post('/videos/{video}/thumbnail', [VideoController::class, 'requestThumbnail'])
    ->name('videos.thumbnail.request');

// Course management routes
Route::resource('courses', \App\Http\Controllers\CourseController::class);
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

// Terminology Management (admin routes)
Route::prefix('admin')->name('admin.')->middleware('auth')->group(function () {
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
    
    // Transcription Presets Routes
    Route::get('/transcription-presets', [App\Http\Controllers\Admin\JobPresetController::class, 'index'])->name('job-presets.index');
    Route::get('/transcription-presets/create', [App\Http\Controllers\Admin\JobPresetController::class, 'create'])->name('job-presets.create');
    Route::post('/transcription-presets', [App\Http\Controllers\Admin\JobPresetController::class, 'store'])->name('job-presets.store');
    Route::get('/transcription-presets/{preset}/edit', [App\Http\Controllers\Admin\JobPresetController::class, 'edit'])->name('job-presets.edit');
    Route::put('/transcription-presets/{preset}', [App\Http\Controllers\Admin\JobPresetController::class, 'update'])->name('job-presets.update');
    Route::delete('/transcription-presets/{preset}', [App\Http\Controllers\Admin\JobPresetController::class, 'destroy'])->name('job-presets.destroy');
    Route::put('/transcription-presets/{preset}/set-default', [App\Http\Controllers\Admin\JobPresetController::class, 'setDefault'])->name('job-presets.set-default');
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

// TrueFire Course Routes
Route::get('/truefire', [App\Http\Controllers\TrueFireController::class, 'index'])->name('truefire.index');
Route::get('/truefire/selection', [App\Http\Controllers\TrueFireController::class, 'selection'])->name('truefire.selection');
Route::get('/truefire/{id}', [App\Http\Controllers\TrueFireController::class, 'show'])->name('truefire.show');
Route::post('/truefire/import/{lessonId}', [App\Http\Controllers\TrueFireController::class, 'importLesson'])->name('truefire.import.lesson');
Route::post('/truefire/import-bulk', [App\Http\Controllers\TrueFireController::class, 'importLessonsBulk'])->name('truefire.import.lessons.bulk');

// Diagnostic Route
Route::get('/diag', function() {
    return response()->json([
        'status' => 'ok',
        'message' => 'Route system is working',
        'time' => now()->toDateTimeString()
    ]);
});

// Channel Routes
Route::get('/channels', [App\Http\Controllers\ChannelController::class, 'index'])->name('channels.index');
Route::get('/channels/{id}', [App\Http\Controllers\ChannelController::class, 'show'])->name('channels.show');
Route::get('/courses/{courseId}/segments', [App\Http\Controllers\ChannelController::class, 'getCourseSegments'])->name('channels.course.segments');
Route::get('/courses/segments', [App\Http\Controllers\ChannelController::class, 'getAllCoursesWithSegments'])->name('channels.all');
Route::get('/courses/segments/example', [App\Http\Controllers\ChannelController::class, 'runExampleQuery'])->name('channels.example');
Route::get('/courses/segments/nested', [App\Http\Controllers\ChannelController::class, 'getNestedStructure'])->name('channels.nested');
Route::post('/segments/import/{segmentId}', [App\Http\Controllers\ChannelController::class, 'importSegment'])->name('channels.import.segment');

// Diagnostic route to check dashboard route
Route::get('/test-dashboard-route', function () {
    return [
        'dashboard_url' => route('dashboard'),
        'can_access_dashboard' => \Illuminate\Support\Facades\Route::has('dashboard'),
        'dashboard_controller_exists' => class_exists(\App\Http\Controllers\DashboardController::class),
        'dashboard_view_exists' => file_exists(resource_path('js/Pages/Dashboard.vue')),
    ];
});

require __DIR__.'/auth.php';
