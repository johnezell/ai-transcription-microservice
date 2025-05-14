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
use App\Http\Controllers\Api\TerminologyController;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Storage;

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
// Route::post('/transcription', [TranscriptionController::class, 'dispatchJob']); // Commented out as it uses the old ProcessTranscriptionJob
Route::get('/transcription/{jobId}', [TranscriptionController::class, 'getJobStatus']);
Route::post('/transcription/{jobId}/status', [TranscriptionController::class, 'updateJobStatus']);
Route::get('/test-python-service', [TranscriptionController::class, 'testPythonService']);

// New terminology routes (using the new controller)
Route::get('/videos/{id}/terminology', [TerminologyController::class, 'show'])->name('api.terminology.show');
Route::post('/videos/{id}/terminology', [TerminologyController::class, 'triggerRecognition'])->name('api.terminology.process');

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
    
    // Force a status update if the video is stuck in transcribed but has terminology
    $status = $video->status;
    if ($status === 'transcribed' && $video->has_terminology) {
        $status = 'completed';
        Log::info('Frontend status check: Video has terminology but status is transcribed - reporting as completed', [
            'video_id' => $video->id,
            'actual_status' => $video->status,
            'reported_status' => $status,
            'has_terminology' => $video->has_terminology,
            'terminology_path' => $video->terminology_path
        ]);
    }
    
    // Calculate progress percentage based on status
    $progressPercentage = 0;
    if ($video->status === 'uploaded') {
        $progressPercentage = 0;
    } elseif ($video->status === 'processing') {
        $progressPercentage = 25; // Audio extraction in progress
    } elseif ($video->status === 'transcribing') {
        $progressPercentage = 50; // Transcription in progress
    } elseif ($video->status === 'transcribed') {
        $progressPercentage = 75; // Transcription complete, waiting for terminology
    } elseif ($video->status === 'processing_music_terms') {
        $progressPercentage = 85; // Terminology recognition in progress
    } elseif ($video->status === 'completed' || $status === 'completed') {
        $progressPercentage = 100;
    }
    
    $response = [
        'success' => true,
        'status' => $status,
        'progress_percentage' => $progressPercentage,
        'video' => [
            'id' => $video->id,
            'original_filename' => $video->original_filename,
            'status' => $status,
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
        Log::warning("[API /videos/{id}] Video not found.", ['video_id' => $id]);
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
    
    // Prefer data from the database column if it exists and is not empty
    if (!empty($video->transcript_json)) {
        Log::debug("[API /transcript-json] Returning transcript JSON from DB column.", ['video_id' => $id]);
        return response()->json($video->transcript_json);
    }
    
    // Fallback to S3 if transcript_json column is empty but transcript_path exists
    if (!empty($video->transcript_path)) {
        $dir = dirname($video->transcript_path);
        $jsonS3Key = $dir . '/transcript.json'; // e.g., s3/jobs/UUID/transcript.json

        Log::debug("[API /transcript-json] Attempting to load transcript JSON from S3.", ['video_id' => $id, 's3_key' => $jsonS3Key]);

        if (Storage::disk('s3')->exists($jsonS3Key)) {
            try {
                $jsonDataString = Storage::disk('s3')->get($jsonS3Key);
                if ($jsonDataString === null) {
                     Log::warning("[API /transcript-json] S3 get() returned null for transcript JSON.", ['video_id' => $id, 's3_key' => $jsonS3Key]);
                     return response()->json(['success' => false, 'message' => 'Transcript data not available (S3 get failed). '], 404);
                }
                $decodedData = json_decode($jsonDataString, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error("[API /transcript-json] Failed to decode transcript JSON from S3.", ['video_id' => $id, 's3_key' => $jsonS3Key, 'json_error' => json_last_error_msg()]);
                    return response()->json(['success' => false, 'message' => 'Error decoding transcript data.'], 500);
                }
                Log::info("[API /transcript-json] Successfully loaded and decoded transcript JSON from S3.", ['video_id' => $id, 's3_key' => $jsonS3Key]);
                return response()->json($decodedData);
            } catch (\Exception $e) {
                Log::error("[API /transcript-json] Exception reading transcript JSON from S3.", ['video_id' => $id, 's3_key' => $jsonS3Key, 'error' => $e->getMessage()]);
                return response()->json(['success' => false, 'message' => 'Error reading transcript data.'], 500);
            }
        } else {
            Log::warning("[API /transcript-json] Transcript JSON file key does not exist on S3.", ['video_id' => $id, 's3_key' => $jsonS3Key]);
        }
    }
    
    // If neither DB nor S3 fallback yielded data
    Log::warning("[API /transcript-json] Transcript data not available in DB or S3.", ['video_id' => $id]);
    return response()->json([
        'success' => false,
        'message' => 'Transcript data not available'
    ], 404);
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

Route::get('/music-terms/export', [\App\Http\Controllers\Api\TerminologyController::class, 'export']);

// Course Analysis API
Route::prefix('courses')->group(function() {
    // Get all terminology across the course
    Route::get('/{course}/terminology', [\App\Http\Controllers\Api\CourseAnalysisController::class, 'getTerminology']);
    
    // Get terminology frequency analysis
    Route::get('/{course}/terminology-frequency', [\App\Http\Controllers\Api\CourseAnalysisController::class, 'getTerminologyFrequency']);
    
    // Get combined transcripts 
    Route::get('/{course}/transcripts', [\App\Http\Controllers\Api\CourseAnalysisController::class, 'getCombinedTranscripts']);
    
    // Search across all transcripts in the course
    Route::post('/{course}/search', [\App\Http\Controllers\Api\CourseAnalysisController::class, 'searchTranscripts']);
});

// Debugging endpoints
Route::post('/test-terminology-callback/{id}', function($id) {
    try {
        // Find the video
        $video = \App\Models\Video::findOrFail($id);
        
        // Get current timestamp
        $now = now();
        
        // Create mock response data similar to what the python service would send
        $responseData = [
            'message' => 'Music term recognition completed successfully',
            'service_timestamp' => now()->toIso8601String(),
            'music_terms_json_path' => '/var/www/storage/app/public/s3/jobs/' . $id . '/music_terms.json',
            'term_count' => 10,
            'categories' => [
                'guitar_parts' => 3,
                'music_theory' => 7
            ],
            'metadata' => [
                'service' => 'music-term-recognition-service',
                'processed_by' => 'test-api-callback'
            ]
        ];
        
        // Create a request to the updateJobStatus method
        $request = new \Illuminate\Http\Request();
        $request->merge([
            'status' => 'completed',
            'completed_at' => now()->toIso8601String(),
            'response_data' => $responseData
        ]);
        
        // Create a transcription controller and call the updateJobStatus method
        $controller = new \App\Http\Controllers\Api\TranscriptionController();
        $response = $controller->updateJobStatus($id, $request);
        
        // Return the response with additional debug info
        return response()->json([
            'success' => true,
            'message' => 'Test callback executed',
            'video_before' => $video->toArray(),
            'controller_response' => json_decode($response->getContent()),
            'status' => \App\Models\Video::find($id)->status
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage()
        ], 500);
    }
});

// New endpoint to debug and fix terminology processing
Route::post('/videos/{id}/debug-terminology', function($id) {
    try {
        // Find the video
        $video = \App\Models\Video::findOrFail($id);
        
        $originalStatus = $video->status;
        $hasTerminologyPath = !empty($video->terminology_path);
        $hasTerminologyFlag = $video->has_terminology;
        $hasTranscript = !empty($video->transcript_path);
        
        // Collect additional diagnostic information
        $diagnostics = [
            'video_id' => $video->id,
            'status' => $originalStatus,
            'has_terminology_path' => $hasTerminologyPath,
            'has_terminology_flag' => $hasTerminologyFlag,
            'has_transcript' => $hasTranscript,
            'transcript_path' => $video->transcript_path,
            'transcript_exists' => $hasTranscript ? file_exists($video->transcript_path) : false,
            'terminology_path' => $video->terminology_path,
            'terminology_exists' => $hasTerminologyPath ? file_exists($video->terminology_path) : false,
            'updated_at' => $video->updated_at
        ];
        
        // Log diagnostic information
        \Illuminate\Support\Facades\Log::info('Terminology processing debug diagnostics', $diagnostics);
        
        // If the video is stuck in transcribed state but should have terminology, attempt to fix it
        if ($video->status === 'transcribed' && $hasTranscript) {
            // Get the terminology controller
            $controller = new \App\Http\Controllers\Api\TerminologyController();
            $request = new \Illuminate\Http\Request();
            
            // Dispatch the job manually to trigger terminology processing
            \App\Jobs\ProcessTerminologyJob::dispatch($video);
            
            return response()->json([
                'success' => true,
                'message' => 'Terminology processing debug initiated',
                'action_taken' => 'Dispatched ProcessTerminologyJob',
                'diagnostics' => $diagnostics
            ]);
        }
        
        // Return the diagnostics without taking any action
        return response()->json([
            'success' => true,
            'message' => 'Terminology processing debug completed',
            'action_taken' => 'None - video in correct state or cannot be fixed',
            'diagnostics' => $diagnostics
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error debugging terminology processing: ' . $e->getMessage(),
            'error' => $e->getMessage()
        ], 500);
    }
});

// Direct status update API endpoint
Route::put('/videos/{id}/status', function($id) {
    try {
        $video = Video::findOrFail($id);
        
        // Record previous status for logging
        $previousStatus = $video->status;
        
        // Force update to completed
        $video->update([
            'status' => 'completed'
        ]);
        
        // Log the status change
        Log::info('Status manually updated via API', [
            'video_id' => $id,
            'from_status' => $previousStatus,
            'to_status' => 'completed'
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Video status updated to completed',
            'previous_status' => $previousStatus,
            'current_status' => 'completed'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error updating video status: ' . $e->getMessage()
        ], 500);
    }
});
