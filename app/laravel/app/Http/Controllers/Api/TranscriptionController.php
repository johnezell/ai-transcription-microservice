<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTranscriptionJob;
use App\Models\TranscriptionLog;
use App\Models\Video;
use Illuminate\Http\Request;
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
        ]);

        // Generate a unique job ID
        $jobId = (string) Str::uuid();

        // Create a transcription log entry
        $log = TranscriptionLog::create([
            'job_id' => $jobId,
            'status' => 'pending',
            'request_data' => $request->all(),
        ]);

        // Dispatch the job to the queue
        ProcessTranscriptionJob::dispatch($log);

        // Return response with job ID
        return response()->json([
            'success' => true,
            'message' => 'Transcription job dispatched successfully',
            'job_id' => $jobId,
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
            'status' => 'required|string|in:processing,completed,failed,extracting_audio,transcribing,processing_music_terms',
            'completed_at' => 'nullable|date',
            'response_data' => 'nullable',
            'error_message' => 'nullable|string',
        ]);
        
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

        // Update the video if it exists
        if ($video) {
            $videoData = [
                'status' => $request->status
            ];

            // If we have response data and it includes audio information
            if ($request->response_data) {
                $responseData = is_array($request->response_data) ? $request->response_data : json_decode($request->response_data, true);
                
                // Audio extraction completed
                if (isset($responseData['audio_path'])) {
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
                    
                    // Audio extraction is complete, trigger transcription service
                    $this->triggerTranscription($video->id);
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
                    
                    // Set status to completed when we receive transcript data
                    $videoData['status'] = 'completed';
                    
                    // Update transcription log with completion data
                    if ($log) {
                        $logUpdateData = [
                            'status' => 'completed',
                            'transcription_completed_at' => $now,
                            'completed_at' => $now,
                            'progress_percentage' => 100
                        ];
                        
                        // Calculate transcription duration if we have the start time
                        if ($log->transcription_started_at) {
                            $transcriptionDuration = $now->diffInSeconds($log->transcription_started_at);
                            $logUpdateData['transcription_duration_seconds'] = $transcriptionDuration;
                        }
                        
                        // Calculate total duration if we have the start time
                        if ($log->started_at) {
                            $totalDuration = $now->diffInSeconds($log->started_at);
                            $logUpdateData['total_processing_duration_seconds'] = $totalDuration;
                        }
                        
                        $log->update($logUpdateData);
                    }
                    
                    // If we have transcript data, also trigger music term recognition
                    if (isset($responseData['transcript_text']) || 
                        (isset($responseData['transcript_path']) && file_exists($responseData['transcript_path']))) {
                        // Trigger music term recognition in the background
                        try {
                            Log::info('Automatically triggering music term recognition after transcription', [
                                'video_id' => $video->id
                            ]);
                            
                            $this->triggerMusicTermRecognition($video->id);
                        } catch (\Exception $e) {
                            // Just log the error but don't fail the whole process
                            Log::error('Failed to trigger music term recognition: ' . $e->getMessage(), [
                                'video_id' => $video->id
                            ]);
                        }
                    }
                }
                
                // Music term recognition completed (update to use terminology fields)
                if (isset($responseData['music_terms_json_path'])) {
                    $videoData['terminology_path'] = $responseData['music_terms_json_path'];
                    $videoData['terminology_count'] = $responseData['term_count'] ?? 0;
                    $videoData['has_terminology'] = true;
                    $videoData['status'] = 'completed';
                    
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
                            'progress_percentage' => 100
                        ];
                        
                        // Calculate music term recognition duration if we have the start time
                        if ($log->music_term_recognition_started_at) {
                            $musicTermDuration = $now->diffInSeconds($log->music_term_recognition_started_at);
                            $musicTermsUpdateData['music_term_recognition_duration_seconds'] = $musicTermDuration;
                        }
                        
                        $log->update($musicTermsUpdateData);
                    }

                    // Log successful processing
                    Log::info('Successfully processed terminology recognition', [
                        'video_id' => $video->id,
                        'term_count' => $responseData['term_count'] ?? 0
                    ]);
                }
            }

            $video->update($videoData);
            
            // If there was no log but we have a video, create a log entry for it
            if (!$log) {
                TranscriptionLog::create([
                    'job_id' => $jobId,
                    'video_id' => $video->id,
                    'status' => $request->status,
                    'response_data' => $request->response_data,
                    'error_message' => $request->error_message,
                    'completed_at' => $request->completed_at ?? $now,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Job status updated successfully',
        ]);
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
     * Trigger the music term recognition service to process a video's transcript.
     *
     * @param  string  $videoId
     * @return bool
     */
    protected function triggerMusicTermRecognition($videoId)
    {
        try {
            // Find the video
            $video = Video::findOrFail($videoId);
            
            // Log that we're dispatching the job
            Log::info('Dispatching music term recognition job for video', [
                'video_id' => $videoId
            ]);
            
            // Dispatch music term recognition job to the queue
            \App\Jobs\MusicTermRecognitionJob::dispatch($video);
            
            return true;
        } catch (\Exception $e) {
            $errorMessage = 'Exception when dispatching music term recognition job: ' . $e->getMessage();
            Log::error($errorMessage, [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);
            
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
