<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\VideoController;
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
    return redirect()->route('videos.index');
});

Route::get('/dashboard', function () {
    return redirect()->route('videos.index');
})->name('dashboard');

// Video management routes - no auth required
Route::resource('videos', VideoController::class);
Route::post('/videos/{video}/transcription', [VideoController::class, 'requestTranscription'])
    ->name('videos.transcription.request');

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

// Auth routes kept for reference but not used
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
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

require __DIR__.'/auth.php';
