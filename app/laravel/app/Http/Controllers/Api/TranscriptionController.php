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
            'status' => 'required|string|in:processing,completed,failed,extracting_audio,transcribing',
            'completed_at' => 'nullable|date',
            'response_data' => 'nullable',
            'error_message' => 'nullable|string',
        ]);

        // Update the log if it exists
        if ($log) {
            $log->update([
                'status' => $request->status,
                'completed_at' => $request->completed_at ?? $log->completed_at,
                'response_data' => $request->response_data ?? $log->response_data,
                'error_message' => $request->error_message ?? $log->error_message,
            ]);
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
                    'completed_at' => $request->completed_at ?? now(),
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
            // Update video status to transcribing
            $video = Video::findOrFail($videoId);
            $video->update(['status' => 'transcribing']);
            
            // Get the transcription service URL from environment
            $transcriptionServiceUrl = env('TRANSCRIPTION_SERVICE_URL', 'http://transcription-service:5000');
            
            // Log the request
            Log::info('Triggering transcription service for video', [
                'video_id' => $videoId,
                'service_url' => $transcriptionServiceUrl
            ]);

            // Send request to the transcription service
            $response = Http::post("{$transcriptionServiceUrl}/process", [
                'job_id' => (string) $videoId
            ]);

            if ($response->successful()) {
                Log::info('Successfully triggered transcription service', [
                    'video_id' => $videoId,
                    'response' => $response->json()
                ]);
                return true;
            } else {
                $errorMessage = 'Failed to trigger transcription service: ' . $response->body();
                Log::error($errorMessage, [
                    'video_id' => $videoId,
                    'error' => $response->body()
                ]);
                // Set status to failed with error message
                $video->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage
                ]);
                return false;
            }
        } catch (\Exception $e) {
            $errorMessage = 'Exception when triggering transcription service: ' . $e->getMessage();
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
     * Test the connection to the Python service.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testPythonService()
    {
        try {
            $pythonServiceUrl = env('PYTHON_SERVICE_URL', 'http://transcription-service:5000');
            $healthUrl = "{$pythonServiceUrl}/health";
            
            $response = Http::get($healthUrl);
            
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
