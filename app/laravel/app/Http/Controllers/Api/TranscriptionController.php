<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\TerminologyRecognitionJob;
use App\Jobs\ProcessTranscriptionJob;
use App\Models\TranscriptionLog;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TranscriptionController extends Controller
{
    /**
     * Dispatch a new transcription job to the queue.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
//    public function dispatchJob(Request $request)
//    {
//        // Validate request
//        $request->validate([
//            'filename' => 'required|string',
//            'type' => 'required|in:audio,video',
//        ]);
//
//        // Generate a unique job ID
//        $jobId = (string) Str::uuid();
//
//        // Create a transcription log entry
//        $log = TranscriptionLog::create([
//            'job_id' => $jobId,
//            'status' => 'pending',
//            'request_data' => $request->all(),
//        ]);
//
//        // Dispatch the job to the queue
//        ProcessTranscriptionJob::dispatch($log);
//
//        // Return response with job ID
//        return response()->json([
//            'success' => true,
//            'message' => 'Transcription job dispatched successfully',
//            'job_id' => $jobId,
//        ], 202);
//    }

    /**
     * Get the status of a transcription job.
     *
     * @param  string  $jobId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getJobStatus($jobId)
    {
        $log = TranscriptionLog::where('job_id', $jobId)->first();
        
        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found',
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'status' => $log->status,
                'started_at' => $log->started_at,
                'completed_at' => $log->completed_at,
                'error_message' => $log->error_message,
            ],
        ]);
    }

    /**
     * Update the status of a transcription job.
     *
     * @param  string  $jobId
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateJobStatus($jobId, Request $request)
    {
        Log::info('[TranscriptionController@updateJobStatus] Received status update request.', [
            'job_id' => $jobId,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            // 'headers' => $request->headers->all(), // Can be very verbose
            'content_type' => $request->getContentTypeFormat(),
            'wants_json' => $request->wantsJson(),
            'payload' => $request->all()
        ]);

        // Get current timestamp
        $now = now();
        
        // Find the transcription log
        $log = TranscriptionLog::where('job_id', $jobId)->first();

        // In case job_id is actually a video ID
        $video = Video::find($jobId);

        if (!$log && !$video) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found',
            ], 404);
        }

        // Validate request
        $request->validate([
            'status' => 'required|string|in:processing,completed,failed,extracting_audio,audio_extracted,transcribing,transcribed,processing_terminology,terminology_extracted',
            'completed_at' => 'nullable|date',
            'response_data' => 'nullable',
            'error_message' => 'nullable|string',
        ]);
        
        // Check if this is a music terms callback with completed status
        $isTerminologyCallback = false;
        $responseData = null;
        
        if ($request->response_data) {
            $responseData = is_array($request->response_data) ? $request->response_data : json_decode($request->response_data, true);
            
            // Check for music terms callback
            if (isset($responseData['music_terms_json_path'])) {
                $isTerminologyCallback = true;
                
                // Log for debugging
                Log::info('Received terminology recognition callback', [
                    'job_id' => $jobId,
                    'requested_status' => $request->status,
                    'response_data' => $responseData
                ]);
            }
            
            // If this includes transcript data but not terminology data, and status is being set to completed,
            // intercept and change it to "transcribed" first so terminology can run
            if (isset($responseData['transcript_path']) && !isset($responseData['music_terms_json_path']) && $request->status === 'completed') {
                Log::info('Intercepting completed status from transcription service and changing to transcribed first', [
                    'job_id' => $jobId,
                    'original_status' => $request->status,
                    'new_status' => 'transcribed',
                    'has_transcript' => true
                ]);
                
                // Modify the request to use "transcribed" status instead
                $request->merge(['status' => 'transcribed']);
            }
        }

        // Wrap the entire operation in a database transaction to prevent race conditions
        return DB::transaction(function() use ($log, $video, $request, $now, $jobId, $responseData, $isTerminologyCallback) {
            
            // Update the log if it exists
            if ($log) {
                $logData = [
                    'status' => $request->status,
                    'error_message' => $request->error_message ?? $log->error_message,
                ];
                
                // Set completion timestamp if provided or if status is completed/failed
                if ($request->completed_at) {
                    $logData['completed_at'] = $request->completed_at;
                } elseif (in_array($request->status, ['completed', 'failed'])) {
                    $logData['completed_at'] = $now;
                }
                
                // Store response data if provided
                if ($request->response_data) {
                    $logData['response_data'] = $request->response_data;
                }
                
                $log->update($logData);
            }

            // Update the video if it exists - process this inside the transaction
            if ($video) {
                // Reload the video to get the latest status - essential to prevent race conditions
                $video = Video::lockForUpdate()->find($video->id);
                
                $videoData = [
                    'status' => $request->status
                ];

                // Special handling for terminology callbacks with completed status - highest priority
                if ($isTerminologyCallback && $request->status === 'completed') {
                    Log::info('Terminology callback with completed status - prioritizing completion status', [
                        'video_id' => $video->id, 
                        'current_status' => $video->status,
                        'requested_status' => $request->status
                    ]);
                    
                    // This is a critical callback that must update the status to completed
                    $videoData['status'] = 'completed';
                }

                // NEVER overwrite a 'completed' status with 'transcribed'
                if ($video->status === 'completed' && $request->status === 'transcribed') {
                    Log::info('Preventing completed status from being overwritten with transcribed', [
                        'video_id' => $video->id,
                        'current_status' => $video->status,
                        'requested_status' => $request->status
                    ]);
                    
                    // Keep the status as completed
                    $videoData['status'] = 'completed';
                }

                // If we have response data and it includes audio information
                if ($request->response_data) {
                    $responseData = is_array($request->response_data) ? $request->response_data : json_decode($request->response_data, true);
                    Log::info('[TranscriptionController@updateJobStatus] Processing response_data.', ['job_id' => $jobId, 'parsed_response_data' => $responseData]); // Log parsed response data
                    
                    // Audio extraction completed
                    if (isset($responseData['audio_path'])) {
                        Log::info('[TranscriptionController@updateJobStatus] Audio path found in response_data.', ['job_id' => $jobId, 'audio_path' => $responseData['audio_path']]);
                        $videoData['audio_path'] = $responseData['audio_path'];
                        $videoData['audio_size'] = $responseData['audio_size_bytes'] ?? null;
                        $videoData['audio_duration'] = $responseData['duration_seconds'] ?? null;
                        
                        // Find or create transcription log
                        if (!$log) {
                            $log = TranscriptionLog::firstOrCreate(
                                ['video_id' => $video->id],
                                [
                                    'job_id' => $video->id,
                                    'status' => 'processing',
                                    'started_at' => $now,
                                ]
                            );
                        }
                        
                        // Update timing information for audio extraction
                        $log->update([
                            'audio_extraction_completed_at' => $now,
                            'audio_file_size' => $responseData['audio_size_bytes'] ?? null,
                            'audio_duration_seconds' => $responseData['duration_seconds'] ?? null,
                            'progress_percentage' => 50, // Audio extraction complete, now 50% done
                        ]);
                        
                        // Calculate audio extraction duration if we have the start time
                        if ($log->audio_extraction_started_at) {
                            $extractionDuration = $now->diffInSeconds($log->audio_extraction_started_at);
                            $log->update([
                                'audio_extraction_duration_seconds' => $extractionDuration
                            ]);
                        }
                        
                        // --- BEGIN IDEMPOTENCY CHECK FOR TranscriptionJob ---
                        // Only dispatch TranscriptionJob if the video is not already transcribing, transcribed, 
                        // processing terminology, or completed/failed.
                        // Reload video to get the absolute latest status before dispatching.
                        $freshVideo = Video::find($video->id);
                        if ($freshVideo && !in_array($freshVideo->status, ['transcribing', 'transcribed', 'processing_terminology', 'completed', 'failed'])) {
                            Log::info('[TranscriptionController@updateJobStatus] Dispatching TranscriptionJob.', ['video_id' => $freshVideo->id, 'current_video_status' => $freshVideo->status]);
                            \App\Jobs\TranscriptionJob::dispatch($freshVideo);
                        } else {
                            Log::info('[TranscriptionController@updateJobStatus] TranscriptionJob NOT dispatched.', [
                                'video_id' => $freshVideo ? $freshVideo->id : ($video ? $video->id : 'null'), 
                                'current_video_status' => $freshVideo ? $freshVideo->status : ($video ? $video->status : 'null'),
                                'reason' => 'Video status indicates transcription already processed, initiated, or video not found.'
                            ]);
                        }
                        // --- END IDEMPOTENCY CHECK --- 
                    }
                    
                    // Transcription completed
                    if (isset($responseData['transcript_path'])) {
                        $videoData['transcript_path'] = $responseData['transcript_path'];
                        
                        // If there's a transcript text in the response, save it
                        if (isset($responseData['transcript_text'])) {
                            $videoData['transcript_text'] = $responseData['transcript_text'];
                        } else if (isset($responseData['transcript_path']) && file_exists($responseData['transcript_path'])) {
                            // Try to read the transcript file if it exists
                            try {
                                $videoData['transcript_text'] = file_get_contents($responseData['transcript_path']);
                            } catch (\Exception $e) {
                                // Log error but continue
                                Log::error('Failed to read transcript file: ' . $e->getMessage());
                            }
                        }
                        
                        // If there's a transcript.json file, save its contents to the database
                        $jsonPath = dirname($responseData['transcript_path']) . '/transcript.json';
                        if (file_exists($jsonPath)) {
                            try {
                                $jsonContent = file_get_contents($jsonPath);
                                $videoData['transcript_json'] = json_decode($jsonContent, true);
                            } catch (\Exception $e) {
                                Log::error('Failed to read transcript JSON file: ' . $e->getMessage());
                            }
                        }
                        
                        // If there's a transcript.srt file, save its contents to the database
                        $srtPath = dirname($responseData['transcript_path']) . '/transcript.srt';
                        if (file_exists($srtPath)) {
                            try {
                                $videoData['transcript_srt'] = file_get_contents($srtPath);
                            } catch (\Exception $e) {
                                Log::error('Failed to read transcript SRT file: ' . $e->getMessage());
                            }
                        }
                        
                        // Always set to 'transcribed' when transcript is processed
                        if (!$isTerminologyCallback && $request->status !== 'completed') {
                            $videoData['status'] = 'transcribed';
                            Log::info('Setting status to transcribed to enable terminology processing', [
                                'video_id' => $video->id,
                                'previous_status' => $video->status,
                                'has_transcript' => true,
                                'transcript_path' => $responseData['transcript_path']
                            ]);
                        }
                        
                        // Update transcription log with completion data
                        if ($log) {
                            $logUpdateData = [
                                'status' => $videoData['status'], // Use the same status we're setting on the video
                                'transcription_completed_at' => $now,
                                'progress_percentage' => 75 // Transcription done, waiting for terminology (75%)
                            ];
                            
                            // Calculate transcription duration if we have the start time
                            if ($log->transcription_started_at) {
                                $transcriptionDuration = $now->diffInSeconds($log->transcription_started_at);
                                $logUpdateData['transcription_duration_seconds'] = $transcriptionDuration;
                            }
                            
                            $log->update($logUpdateData);
                        }

                        // After transcript processing, if status is 'transcribed', dispatch TerminologyRecognitionJob
                        // Ensure this is done *after* $videoData has been prepared but before $video->update usually, 
                        // or rely on DB::afterCommit if $video needs to be saved first for the job to have the latest state.
                        // For simplicity here, we'll dispatch assuming the Video model instance is sufficient.
                        if ($videoData['status'] === 'transcribed' && !$isTerminologyCallback) {
                            Log::info('[TranscriptionController@updateJobStatus] Dispatching TerminologyRecognitionJob.', ['video_id' => $video->id]);
                            \App\Jobs\TerminologyRecognitionJob::dispatch($video);
                        }
                    }
                    
                    // Terminology recognition completed (this block processes the callback FROM Terminology Service)
                    if (isset($responseData['terminology_path'])) {
                        Log::info('[TranscriptionController@updateJobStatus] Terminology path found in response_data.', ['job_id' => $jobId, 'terminology_path' => $responseData['terminology_path']]);
                        $videoData['terminology_path'] = $responseData['terminology_path'];
                        $videoData['terminology_count'] = $responseData['term_count'] ?? ($responseData['unique_term_count'] ?? 0);
                        $videoData['has_terminology'] = true;
                        if(isset($responseData['category_summary'])) {
                            $currentMetadata = $video->terminology_metadata ?? []; // Ensure currentMetadata is an array
                            $videoData['terminology_metadata'] = array_merge($currentMetadata, ['category_summary' => $responseData['category_summary']]);
                        }
                        // $videoData['terminology_json'] = $responseData['terms'] ?? null; // Python service currently doesn't send full 'terms' in callback payload
                        // The Video model accessor getTerminologyJsonDataAttribute will fetch from S3 if this is null.
                        
                        $videoData['status'] = 'completed'; // This is now the final step
                        
                        Log::info('Processing terminology recognition callback - setting status to completed', [
                            'video_id' => $video->id, 
                            'new_status' => 'completed',
                            'response_data' => $responseData
                        ]);
                        
                        if ($log) {
                            $logUpdateData = [
                                'status' => 'completed',
                                'terminology_analysis_completed_at' => $now, // Using new field name
                                'terminology_term_count' => $videoData['terminology_count'], // Using new field name
                                'completed_at' => $now,
                                'progress_percentage' => 100
                            ];
                            // Optionally, calculate and add 'terminology_duration_seconds' if 'terminology_analysis_started_at' is reliably set on $log
                            if ($log->terminology_analysis_started_at) { // Check if start time was set
                                $terminologyDuration = $now->diffInSeconds($log->terminology_analysis_started_at);
                                $logUpdateData['terminology_duration_seconds'] = $terminologyDuration;
                            }
                            $log->update($logUpdateData);
                        }
                    }
                }

                // Force 'completed' status for videos that have finished terminology processing or have terminology data
                if (($video->status === 'transcribed' || $video->status === 'processing_music_terms') && 
                    ((isset($videoData['has_terminology']) && $videoData['has_terminology']) || $video->has_terminology)) {
                    $videoData['status'] = 'completed';
                    Log::info('Forcing status update to completed after terminology recognition', [
                        'video_id' => $video->id,
                        'previous_status' => $video->status,
                        'new_status' => 'completed'
                    ]);
                }

                // Add debug logging for all videos that are in a strange state
                if ($video->has_terminology && $video->status !== 'completed') {
                    Log::warning('Video with terminology is not in completed state', [
                        'video_id' => $video->id, 
                        'status' => $video->status,
                        'has_terminology' => $video->has_terminology,
                        'has_music_terms' => $video->has_music_terms
                    ]);
                }

                // Log the final status value right before saving
                Log::info('Final status value before database update', [
                    'video_id' => $video->id,
                    'original_status' => $video->status,
                    'new_status' => $videoData['status'],
                    'is_terminology_callback' => $isTerminologyCallback,
                    'request_status' => $request->status,
                ]);

                // Commit the database update from within the transaction
                $video->update($videoData);
                
                // If there was no log but we have a video, create a log entry for it
                if (!$log) {
                    TranscriptionLog::create([
                        'job_id' => $jobId,
                        'video_id' => $video->id,
                        'status' => $videoData['status'], // Use the same status we set on the video
                        'response_data' => $request->response_data,
                        'error_message' => $request->error_message,
                        'completed_at' => $request->completed_at ?? $now,
                    ]);
                }
                
                // Only trigger terminology recognition after the transcription is finished and it's safe to do so
                // We wait for the transaction to finish first to avoid race conditions
                if (isset($responseData['transcript_path']) && 
                    !$isTerminologyCallback && 
                    $videoData['status'] === 'transcribed') {
                    
                    try {
                        // Fetch the video again to ensure we have the latest data
                        $videoForTerminology = Video::find($video->id);
                        
                        // Check if we have a transcript path
                        if (!empty($videoForTerminology->transcript_path) && file_exists($videoForTerminology->transcript_path)) {
                            // Schedule the task to run after this transaction is committed
                            DB::afterCommit(function () use ($video) {
                                Log::info('Automatically triggering terminology recognition after transcription in afterCommit', [
                                    'video_id' => $video->id
                                ]);
                                
                                // Use a queued job rather than direct call to avoid race conditions
                                TerminologyRecognitionJob::dispatch($video)->delay(now()->addSeconds(2));
                            });
                        }
                    } catch (\Exception $e) {
                        // Just log the error but don't fail the whole process
                        Log::error('Failed to schedule terminology recognition: ' . $e->getMessage(), [
                            'video_id' => $video->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            Log::info('[TranscriptionController@updateJobStatus] Returning JSON response.', ['job_id' => $jobId, 'success' => true]);
            return response()->json([
                'success' => true,
                'message' => 'Job status updated successfully',
            ]);
        });
    }

    /**
     * Trigger the transcription service to process a video's audio file.
     *
     * @param  string  $videoId
     * @return bool
     */
    protected function triggerTranscription($videoId)
    {
        try {
            // Find the video
            $video = Video::findOrFail($videoId);
            
            // Log that we're dispatching the job
            Log::info('Dispatching transcription job for video', [
                'video_id' => $videoId
            ]);
            
            // Dispatch transcription job to the queue
            \App\Jobs\TranscriptionJob::dispatch($video);
            
            return true;
        } catch (\Exception $e) {
            $errorMessage = 'Exception when dispatching transcription job: ' . $e->getMessage();
            Log::error($errorMessage, [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);
            
            // Try to update status to failed on exception
            try {
                $video = Video::find($videoId);
                if ($video) {
                    $video->update([
                        'status' => 'failed',
                        'error_message' => $errorMessage
                    ]);
                }
            } catch (\Exception $innerEx) {
                Log::error('Failed to update error status', [
                    'video_id' => $videoId,
                    'error' => $innerEx->getMessage()
                ]);
            }
            
            return false;
        }
    }

    /**
     * Trigger the terminology recognition service to process a video's transcript.
     * This method replaces triggerMusicTermRecognition for a more general terminology approach.
     *
     * @param  string  $videoId
     * @return bool
     */
    protected function triggerTerminologyRecognition($videoId)
    {
        try {
            // Instead of duplicating logic, use the same controller as the API endpoint
            $terminologyController = new \App\Http\Controllers\Api\TerminologyController();
            $request = new \Illuminate\Http\Request();
            
            // Call the process method directly
            $response = $terminologyController->process($request, $videoId);
            
            // If the response is successful, return true
            if ($response && $response->status() === 200) {
                Log::info('Successfully triggered terminology recognition via controller', [
                    'video_id' => $videoId
                ]);
                return true;
            }
            
            // If we get here, something went wrong
            $responseData = [];
            if ($response) {
                $responseData = json_decode($response->getContent(), true);
            }
            $errorMessage = $responseData['message'] ?? 'Unknown error';
            
            Log::error('Failed to trigger terminology recognition: ' . $errorMessage, [
                'video_id' => $videoId,
                'response' => $responseData
            ]);
            
            return false;
        } catch (\Exception $e) {
            $errorMessage = 'Exception when dispatching terminology recognition job: ' . $e->getMessage();
            Log::error($errorMessage, [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Try to update the video status but don't fail if we can't
            try {
                $video = Video::find($videoId);
                if ($video) {
                    $video->update([
                        'status' => 'completed',
                        'error_message' => 'Terminology recognition failed: ' . $e->getMessage()
                    ]);
                }
            } catch (\Exception $innerEx) {
                Log::error('Failed to update video after terminology recognition error', [
                    'video_id' => $videoId,
                    'error' => $innerEx->getMessage()
                ]);
            }
            
            return false;
        }
    }

    /**
     * Test the connection to the Python service.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testPythonService()
    {
        try {
            $pythonServiceUrl = env('PYTHON_SERVICE_URL', 'http://transcription-service:5000');
            $healthUrl = "{$pythonServiceUrl}/health";
            
            $response = Http::timeout(120)->get($healthUrl);
            
            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Successfully connected to Python service',
                    'python_service_response' => $response->json(),
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to connect to Python service',
                    'status_code' => $response->status(),
                    'response' => $response->body(),
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to connect to Python service',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
