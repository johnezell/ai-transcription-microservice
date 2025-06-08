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
    public function dispatchJob(Request $request)
    {
        // Validate request
        $request->validate([
            'filename' => 'required|string',
            'type' => 'required|in:audio,video',
            'video_id' => 'sometimes|integer|exists:videos,id',
            'truefire_course_id' => 'sometimes|integer|exists:local_truefire_courses,id',
            'transcription_preset' => 'sometimes|string|in:fast,balanced,high,premium'
        ]);

        // Generate a unique job ID
        $jobId = (string) Str::uuid();

        // Determine transcription preset to use
        $transcriptionPreset = $this->determineTranscriptionPreset($request);

        // Create a transcription log entry
        $log = TranscriptionLog::create([
            'job_id' => $jobId,
            'status' => 'pending',
            'request_data' => $request->all(),
            'preset_used' => $transcriptionPreset,
        ]);

        // Dispatch the job to the queue with preset
        ProcessTranscriptionJob::dispatch($log, $transcriptionPreset);

        Log::info('Transcription job dispatched with preset', [
            'job_id' => $jobId,
            'preset' => $transcriptionPreset,
            'video_id' => $request->get('video_id'),
            'truefire_course_id' => $request->get('truefire_course_id')
        ]);

        // Return response with job ID and preset information
        return response()->json([
            'success' => true,
            'message' => 'Transcription job dispatched successfully',
            'job_id' => $jobId,
            'transcription_preset' => $transcriptionPreset,
        ], 202);
    }

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
            'status' => 'required|string|in:processing,completed,failed,extracting_audio,transcribing,transcribed,processing_music_terms',
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
                    
                    // Audio extraction completed
                    if (isset($responseData['audio_path'])) {
                        $videoData['audio_path'] = $responseData['audio_path'];
                        $videoData['audio_size'] = $responseData['audio_size_bytes'] ?? null;
                        $videoData['audio_duration'] = $responseData['duration_seconds'] ?? null;
                        
                        // Set status to indicate audio extraction is complete but awaiting approval
                        $videoData['status'] = 'audio_extracted';
                        
                        // Find or create transcription log
                        if (!$log) {
                            $log = TranscriptionLog::firstOrCreate(
                                ['video_id' => $video->id],
                                [
                                    'job_id' => $video->id,
                                    'status' => 'audio_extracted',
                                    'started_at' => $now,
                                ]
                            );
                        }
                        
                        // Update timing information for audio extraction
                        $log->update([
                            'status' => 'audio_extracted',
                            'audio_extraction_completed_at' => $now,
                            'audio_file_size' => $responseData['audio_size_bytes'] ?? null,
                            'audio_duration_seconds' => $responseData['duration_seconds'] ?? null,
                            'progress_percentage' => 40, // Audio extraction complete, waiting for approval
                        ]);
                        
                        // Calculate audio extraction duration if we have the start time
                        if ($log->audio_extraction_started_at) {
                            $extractionDuration = $now->diffInSeconds($log->audio_extraction_started_at);
                            $log->update([
                                'audio_extraction_duration_seconds' => $extractionDuration
                            ]);
                        }
                        
                        // Log that audio extraction is complete and waiting for approval
                        Log::info('Audio extraction completed, waiting for manual approval', [
                            'video_id' => $video->id,
                            'audio_path' => $responseData['audio_path'],
                            'audio_duration' => $responseData['duration_seconds'] ?? null,
                            'audio_size' => $responseData['audio_size_bytes'] ?? null
                        ]);
                        
                        // DO NOT automatically trigger transcription - wait for manual approval
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
                        
                        // Always set to 'transcribed' when transcript is processed, so terminology can run
                        // Unless this is a terminology callback that's trying to mark it completed
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
                    }
                    
                    // Music term recognition completed (update to use terminology fields)
                    if (isset($responseData['music_terms_json_path'])) {
                        $videoData['terminology_path'] = $responseData['music_terms_json_path'];
                        $videoData['terminology_count'] = $responseData['term_count'] ?? 0;
                        $videoData['has_terminology'] = true;
                        $videoData['status'] = 'completed'; // Now it's safe to mark as completed
                        
                        // Add detailed log to debug terminology callback processing
                        Log::info('Processing terminology recognition callback', [
                            'video_id' => $video->id,
                            'previous_status' => $video->status,
                            'new_status' => 'completed',
                            'has_terminology' => true,
                            'term_count' => $responseData['term_count'] ?? 0,
                            'terminology_path' => $responseData['music_terms_json_path'],
                            'response_data' => $responseData
                        ]);
                        
                        // Save category breakdown as metadata
                        if (isset($responseData['categories'])) {
                            $videoData['terminology_metadata'] = [
                                'categories' => $responseData['categories'],
                                'service_timestamp' => $responseData['service_timestamp'] ?? now()->toIso8601String(),
                            ];
                        }
                        
                        // Save the terminology JSON file content to database
                        if (file_exists($responseData['music_terms_json_path'])) {
                            try {
                                $jsonContent = file_get_contents($responseData['music_terms_json_path']);
                                $videoData['terminology_json'] = json_decode($jsonContent, true);
                            } catch (\Exception $e) {
                                Log::error('Failed to read terminology JSON file: ' . $e->getMessage());
                            }
                        }
                        
                        // Update transcription log with completion data
                        if ($log) {
                            $musicTermsUpdateData = [
                                'status' => 'completed',
                                'music_term_recognition_completed_at' => $now,
                                'music_term_count' => $responseData['term_count'] ?? 0,
                                'completed_at' => $now,
                                'progress_percentage' => 100
                            ];
                            
                            // Calculate music term recognition duration if we have the start time
                            if ($log->music_term_recognition_started_at) {
                                $musicTermDuration = $now->diffInSeconds($log->music_term_recognition_started_at);
                                $musicTermsUpdateData['music_term_recognition_duration_seconds'] = $musicTermDuration;
                            }
                            
                            // Calculate total duration from start 
                            if ($log->started_at) {
                                $totalDuration = $now->diffInSeconds($log->started_at);
                                $musicTermsUpdateData['total_processing_duration_seconds'] = $totalDuration;
                            }
                            
                            $log->update($musicTermsUpdateData);
                        }

                        // Log successful processing
                        Log::info('Successfully processed terminology recognition', [
                            'video_id' => $video->id,
                            'term_count' => $responseData['term_count'] ?? 0,
                            'path' => $responseData['music_terms_json_path']
                        ]);
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

    /**
     * Determine which transcription preset to use for the job
     */
    protected function determineTranscriptionPreset(Request $request): string
    {
        // If preset was explicitly provided in request, use it
        if ($request->has('transcription_preset')) {
            Log::info('Using explicitly provided transcription preset', [
                'preset' => $request->get('transcription_preset')
            ]);
            return $request->get('transcription_preset');
        }

        // Try to get preset from video's course
        if ($request->has('video_id')) {
            $video = Video::find($request->get('video_id'));
            if ($video && $video->course_id) {
                $coursePreset = \App\Models\CourseTranscriptionPreset::getPresetForCourse($video->course_id);
                if ($coursePreset) {
                    Log::info('Using course transcription preset from video', [
                        'video_id' => $video->id,
                        'course_id' => $video->course_id,
                        'preset' => $coursePreset
                    ]);
                    return $coursePreset;
                }
            }
        }

        // Try to get preset from TrueFire course
        if ($request->has('truefire_course_id')) {
            $courseId = $request->get('truefire_course_id');
            $coursePreset = \App\Models\CourseTranscriptionPreset::getPresetForCourse($courseId);
            if ($coursePreset) {
                Log::info('Using TrueFire course transcription preset', [
                    'truefire_course_id' => $courseId,
                    'preset' => $coursePreset
                ]);
                return $coursePreset;
            }
        }

        // Fall back to default preset
        $defaultPreset = config('transcription_presets.default_preset', 'balanced');
        Log::info('Using default transcription preset', [
            'preset' => $defaultPreset
        ]);
        
        return $defaultPreset;
    }
}
