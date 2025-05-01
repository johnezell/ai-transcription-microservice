<?php

use App\Http\Controllers\Api\ConnectivityController;
use App\Http\Controllers\Api\HelloController;
use App\Http\Controllers\Api\MusicTermController;
use App\Http\Controllers\Api\TranscriptionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\Video;
use App\Models\TranscriptionLog;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Hello World endpoint
Route::get('/hello', [HelloController::class, 'hello'])->name('api.hello');

// Connectivity test
Route::get('/connectivity-test', [ConnectivityController::class, 'testConnectivity'])->name('api.connectivity-test');

// Transcription endpoints
Route::post('/transcription', [TranscriptionController::class, 'dispatchJob']);
Route::get('/transcription/{jobId}', [TranscriptionController::class, 'getJobStatus']);
Route::post('/transcription/{jobId}/status', [TranscriptionController::class, 'updateJobStatus']);
Route::get('/test-python-service', [TranscriptionController::class, 'testPythonService']);

// Music term recognition endpoints
Route::get('/videos/{id}/music-terms', [MusicTermController::class, 'show'])->name('api.music-terms.show');
Route::post('/videos/{id}/music-terms', [MusicTermController::class, 'process'])->name('api.music-terms.process');

// Trigger music term recognition (keep for backward compatibility)
Route::post('/videos/{id}/music-terms', [MusicTermController::class, 'triggerRecognition']);

// New terminology route (replaces music-terms for general terminology)
Route::post('/videos/{id}/terminology', [MusicTermController::class, 'triggerRecognition']);

// Status polling endpoint for video processing
Route::get('/videos/{id}/status', function($id) {
    $video = Video::find($id);
    
    if (!$video) {
        return response()->json([
            'success' => false,
            'message' => 'Video not found'
        ], 404);
    }
    
    // Get the transcription log if available
    $log = TranscriptionLog::where('video_id', $id)->first();
    
    // Calculate progress percentage based on status
    $progressPercentage = 0;
    if ($video->status === 'uploaded') {
        $progressPercentage = 0;
    } elseif ($video->status === 'processing') {
        $progressPercentage = 25; // Audio extraction in progress
    } elseif ($video->status === 'transcribing') {
        $progressPercentage = 75; // Transcription in progress
    } elseif ($video->status === 'processing_music_terms') {
        $progressPercentage = 85; // Music term recognition in progress
    } elseif ($video->status === 'completed') {
        $progressPercentage = 100;
    }
    
    $response = [
        'success' => true,
        'status' => $video->status,
        'progress_percentage' => $progressPercentage,
        'video' => [
            'id' => $video->id,
            'original_filename' => $video->original_filename,
            'status' => $video->status,
            'created_at' => $video->created_at,
            'updated_at' => $video->updated_at,
            'has_audio' => !empty($video->audio_path),
            'has_transcript' => !empty($video->transcript_path),
            'has_music_terms' => $video->has_music_terms,
            'error_message' => $video->error_message,
        ]
    ];
    
    // Add timing details if log exists
    if ($log) {
        $response['timing'] = [
            'started_at' => $log->started_at,
            'audio_extraction_started_at' => $log->audio_extraction_started_at,
            'audio_extraction_completed_at' => $log->audio_extraction_completed_at,
            'transcription_started_at' => $log->transcription_started_at,
            'transcription_completed_at' => $log->transcription_completed_at,
            'music_term_recognition_started_at' => $log->music_term_recognition_started_at,
            'music_term_recognition_completed_at' => $log->music_term_recognition_completed_at,
            'completed_at' => $log->completed_at,
            'audio_extraction_duration_seconds' => $log->audio_extraction_duration_seconds,
            'transcription_duration_seconds' => $log->transcription_duration_seconds,
            'music_term_recognition_duration_seconds' => $log->music_term_recognition_duration_seconds,
            'total_processing_duration_seconds' => $log->total_processing_duration_seconds,
        ];
        
        // If there's detailed progress information
        if ($log->progress_percentage > 0) {
            $response['progress_percentage'] = $log->progress_percentage;
        }
    }
    
    // Add media details if available
    if ($video->audio_path) {
        $response['media'] = [
            'audio_size' => $video->audio_size,
            'audio_duration' => $video->audio_duration,
            'audio_url' => $video->audio_url,
        ];
    }
    
    // Add transcript details if available
    if ($video->transcript_path) {
        $response['transcript'] = [
            'transcript_url' => $video->transcript_url,
            'transcript_json_url' => $video->transcript_json_url,
            'transcript_excerpt' => Str::limit($video->transcript_text, 200),
        ];
    }
    
    // Add music terms details if available
    if ($video->has_music_terms) {
        $response['music_terms'] = [
            'music_terms_url' => $video->music_terms_url,
            'music_terms_count' => $video->music_terms_count,
            'music_terms_metadata' => $video->music_terms_metadata,
        ];
    }
    
    // Add direct paths to the important video data
    $response['video']['url'] = $video->url;
    $response['video']['audio_url'] = $video->audio_url;
    $response['video']['transcript_url'] = $video->transcript_url;
    $response['video']['subtitles_url'] = $video->subtitles_url;
    $response['video']['transcript_json_url'] = $video->transcript_json_url;
    $response['video']['transcript_json_api_url'] = $video->transcript_json_api_url;
    $response['video']['music_terms_url'] = $video->music_terms_url;
    $response['video']['terminology_url'] = $video->terminology_url;
    $response['video']['terminology_json_api_url'] = $video->terminology_json_api_url;
    $response['video']['music_terms_count'] = $video->music_terms_count;
    $response['video']['music_terms_metadata'] = $video->music_terms_metadata;
    $response['video']['formatted_duration'] = $video->formatted_duration;
    $response['video']['is_processing'] = $video->is_processing;
    
    return response()->json($response);
});

// Get a single video
Route::get('/videos/{id}', function($id) {
    $video = Video::find($id);
    
    if (!$video) {
        return response()->json([
            'success' => false,
            'message' => 'Video not found'
        ], 404);
    }
    
    // Get full video details with all accessors
    return response()->json([
        'success' => true,
        'video' => $video->toArray() + [
            'url' => $video->url,
            'audio_url' => $video->audio_url,
            'transcript_url' => $video->transcript_url,
            'subtitles_url' => $video->subtitles_url,
            'transcript_json_url' => $video->transcript_json_url,
            'transcript_json_api_url' => $video->transcript_json_api_url,
            'terminology_url' => $video->terminology_url,
            'terminology_json_api_url' => $video->terminology_json_api_url,
            'formatted_duration' => $video->formatted_duration,
            'is_processing' => $video->is_processing,
        ]
    ]);
});

// New endpoints for accessing data directly from the database
Route::get('/videos/{id}/transcript-json', function($id) {
    $video = Video::find($id);
    
    if (!$video) {
        return response()->json([
            'success' => false,
            'message' => 'Video not found'
        ], 404);
    }
    
    if (empty($video->transcript_json)) {
        // Fallback to file if database doesn't have the data
        if (!empty($video->transcript_path)) {
            $dir = dirname($video->transcript_path);
            $jsonPath = $dir . '/transcript.json';
            if (file_exists($jsonPath)) {
                $jsonData = json_decode(file_get_contents($jsonPath), true);
                return response()->json($jsonData);
            }
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Transcript data not available'
        ], 404);
    }
    
    return response()->json($video->transcript_json);
});

Route::get('/videos/{id}/terminology-json', function($id) {
    $video = Video::find($id);
    
    if (!$video) {
        return response()->json([
            'success' => false,
            'message' => 'Video not found'
        ], 404);
    }
    
    if (empty($video->terminology_json)) {
        // Fallback to file if database doesn't have the data
        if (!empty($video->terminology_path)) {
            if (file_exists($video->terminology_path)) {
                $jsonData = json_decode(file_get_contents($video->terminology_path), true);
                return response()->json($jsonData);
            }
        }
        
        // Also try the music_terms path for backward compatibility
        if (!empty($video->music_terms_path)) {
            if (file_exists($video->music_terms_path)) {
                $jsonData = json_decode(file_get_contents($video->music_terms_path), true);
                return response()->json($jsonData);
            }
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Terminology data not available'
        ], 404);
    }
    
    return response()->json($video->terminology_json);
});
