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
use Illuminate\Support\Facades\Storage;

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
            'content_type' => $request->getContentTypeFormat(),
            'wants_json' => $request->wantsJson(),
            'payload' => $request->all()
        ]);

        $now = now();
        $log = TranscriptionLog::where('job_id', $jobId)->first();
        $video = Video::find($jobId);

        if (!$log && !$video) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found',
            ], 404);
        }

        $request->validate([
            'status' => 'required|string|in:processing,completed,failed,extracting_audio,audio_extracted,transcribing,transcribed,processing_terminology,terminology_extracted',
            'completed_at' => 'nullable|date',
            'response_data' => 'nullable',
            'error_message' => 'nullable|string',
        ]);
        
        $isTerminologyCallback = false;
        $responseData = null;
        
        if ($request->response_data) {
            $responseData = is_array($request->response_data) ? $request->response_data : json_decode($request->response_data, true);
            if (isset($responseData['music_terms_json_path'])) {
                $isTerminologyCallback = true;
                Log::info('Received terminology recognition callback', [
                    'job_id' => $jobId,
                    'requested_status' => $request->status,
                    'response_data' => $responseData
                ]);
            }
            if (isset($responseData['transcript_path']) && !isset($responseData['music_terms_json_path']) && $request->status === 'completed') {
                Log::info('Intercepting completed status from transcription service and changing to transcribed first', [
                    'job_id' => $jobId,
                    'original_status' => $request->status,
                    'new_status' => 'transcribed',
                    'has_transcript' => true
                ]);
                $request->merge(['status' => 'transcribed']);
            }
        }

        return DB::transaction(function() use ($log, $video, $request, $now, $jobId, $responseData, $isTerminologyCallback) {
            if ($log) {
                $logData = [
                    'status' => $request->status,
                    'error_message' => $request->error_message ?? $log->error_message,
                ];
                
                if ($request->completed_at) {
                    $logData['completed_at'] = $request->completed_at;
                } elseif (in_array($request->status, ['completed', 'failed'])) {
                    $logData['completed_at'] = $now;
                }
                
                if ($request->response_data) {
                    $logData['response_data'] = $request->response_data;
                }
                
                $log->update($logData);
            }

            if ($video) {
                $video = Video::lockForUpdate()->find($video->id);
                $videoData = ['status' => $request->status];

                if ($isTerminologyCallback && $request->status === 'completed') {
                    Log::info('Terminology callback with completed status - prioritizing completion status', [
                        'video_id' => $video->id, 
                        'current_status' => $video->status,
                        'requested_status' => $request->status
                    ]);
                    
                    $videoData['status'] = 'completed';
                }

                if ($video->status === 'completed' && $request->status === 'transcribed') {
                    Log::info('Preventing completed status from being overwritten with transcribed', [
                        'video_id' => $video->id,
                        'current_status' => $video->status,
                        'requested_status' => $request->status
                    ]);
                    
                    $videoData['status'] = 'completed';
                }

                if ($request->response_data) {
                    Log::info('[TranscriptionController@updateJobStatus] Processing response_data.', ['job_id' => $jobId, 'parsed_response_data' => $responseData]);
                    
                    if (isset($responseData['audio_path'])) {
                        Log::info('[TranscriptionController@updateJobStatus] Audio path found in response_data.', ['job_id' => $jobId, 'audio_path' => $responseData['audio_path']]);
                        $videoData['audio_path'] = $responseData['audio_path'];
                        $videoData['audio_size'] = $responseData['audio_size_bytes'] ?? null;
                        $videoData['audio_duration'] = $responseData['duration_seconds'] ?? null;
                        
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
                        
                        $log->update([
                            'audio_extraction_completed_at' => $now,
                            'audio_file_size' => $responseData['audio_size_bytes'] ?? null,
                            'audio_duration_seconds' => $responseData['duration_seconds'] ?? null,
                            'progress_percentage' => 50,
                        ]);
                        
                        if ($log->audio_extraction_started_at) {
                            $extractionDuration = $now->diffInSeconds($log->audio_extraction_started_at);
                            $log->update([
                                'audio_extraction_duration_seconds' => $extractionDuration
                            ]);
                        }
                        
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
                    }
                    
                    if (isset($responseData['transcript_path'])) {
                        $s3TranscriptTxtPath = $responseData['transcript_path'];
                        $videoData['transcript_path'] = $s3TranscriptTxtPath;
                        Log::info('[TranscriptionController@updateJobStatus] Processing transcript data from S3.', ['video_id' => $video->id, 's3_txt_path' => $s3TranscriptTxtPath]);

                        if (Storage::disk('s3')->exists($s3TranscriptTxtPath)) {
                            Log::info('[TranscriptionController@updateJobStatus] transcript.txt FOUND on S3.', ['video_id' => $video->id, 's3_path' => $s3TranscriptTxtPath]);
                            try {
                                $transcriptContent = Storage::disk('s3')->get($s3TranscriptTxtPath);
                                if ($transcriptContent !== null && $transcriptContent !== false) {
                                    $videoData['transcript_text'] = $transcriptContent;
                                    Log::info('Successfully read transcript_text from transcript.txt on S3.', ['video_id' => $video->id, 'text_length' => strlen($transcriptContent)]);
                                } else {
                                    Log::warning('Failed to get content from transcript.txt on S3 (content was null or false).', ['video_id' => $video->id, 's3_path' => $s3TranscriptTxtPath]);
                                }
                            } catch (\Exception $e) {
                                Log::error('Exception reading transcript_text from transcript.txt on S3: ' . $e->getMessage(), ['video_id' => $video->id, 's3_path' => $s3TranscriptTxtPath]);
                            }
                        } else {
                            Log::warning('transcript.txt (from transcript_path) does NOT exist on S3.', ['video_id' => $video->id, 's3_path' => $s3TranscriptTxtPath]);
                        }

                        $s3JsonPath = dirname($s3TranscriptTxtPath) . '/transcript.json';
                        if (Storage::disk('s3')->exists($s3JsonPath)) {
                            try {
                                $jsonContent = Storage::disk('s3')->get($s3JsonPath);
                                $decodedJson = json_decode($jsonContent, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                   $videoData['transcript_json'] = $decodedJson;
                                   Log::info('Successfully read and decoded transcript_json from S3.', ['video_id' => $video->id]);

                                   if (empty($videoData['transcript_text']) && isset($decodedJson['text'])) {
                                       $videoData['transcript_text'] = $decodedJson['text'];
                                       Log::info('Populated transcript_text from transcript.json as fallback/override.', ['video_id' => $video->id, 'text_length' => strlen($decodedJson['text'])]);
                                   } else if (!empty($videoData['transcript_text']) && isset($decodedJson['text']) && $videoData['transcript_text'] !== $decodedJson['text']) {
                                       Log::warning('transcript_text from transcript.txt differs from text in transcript.json.', ['video_id' => $video->id]);
                                   }

                                } else {
                                   Log::error('Failed to decode transcript_json from S3 content.', ['video_id' => $video->id, 'json_error' => json_last_error_msg()]);
                                }
                            } catch (\Exception $e) {
                                Log::error('Exception reading/processing transcript_json from S3: ' . $e->getMessage(), ['video_id' => $video->id]);
                            }
                        } else {
                            Log::warning('Derived transcript_json_path does not exist on S3.', ['video_id' => $video->id, 's3_path' => $s3JsonPath]);
                        }
                        
                        if (empty($videoData['transcript_text'])) {
                            $videoData['transcript_text'] = null;
                            Log::warning('transcript_text remains empty after attempting to read from .txt and .json.', ['video_id' => $video->id]);
                        }

                        $s3SrtPath = dirname($s3TranscriptTxtPath) . '/transcript.srt';
                        if (Storage::disk('s3')->exists($s3SrtPath)) {
                           try {
                                $videoData['transcript_srt'] = Storage::disk('s3')->get($s3SrtPath);
                                Log::info('Successfully read transcript_srt from S3.', ['video_id' => $video->id]);
                            } catch (\Exception $e) {
                                Log::error('Exception reading transcript_srt from S3: ' . $e->getMessage(), ['video_id' => $video->id]);
                            }
                        } else {
                           Log::warning('Derived transcript_srt_path does not exist on S3.', ['video_id' => $video->id, 's3_path' => $s3SrtPath]);
                        }
                        
                        if (!$isTerminologyCallback && $request->status !== 'completed') {
                            $videoData['status'] = 'transcribed';
                            Log::info('Setting status to transcribed to enable terminology processing', [
                                'video_id' => $video->id,
                                'previous_status' => $video->status,
                                'has_transcript' => true,
                                'transcript_path' => $responseData['transcript_path']
                            ]);
                        }
                        
                        if ($log) {
                            $logUpdateData = [
                                'status' => $videoData['status'],
                                'transcription_completed_at' => $now,
                                'progress_percentage' => 75
                            ];
                            
                            if ($log->transcription_started_at) {
                                $transcriptionDuration = $now->diffInSeconds($log->transcription_started_at);
                                $logUpdateData['transcription_duration_seconds'] = $transcriptionDuration;
                            }
                            
                            $log->update($logUpdateData);
                        }

                        if ($videoData['status'] === 'transcribed' && !$isTerminologyCallback) {
                            Log::info('[TranscriptionController@updateJobStatus] Dispatching TerminologyRecognitionJob.', ['video_id' => $video->id]);
                            \App\Jobs\TerminologyRecognitionJob::dispatch($video);
                        }
                    }
                    
                    if (isset($responseData['terminology_path'])) {
                        Log::info('[TranscriptionController@updateJobStatus] Terminology path found in response_data.', ['job_id' => $jobId, 'terminology_path' => $responseData['terminology_path']]);
                        $videoData['terminology_path'] = $responseData['terminology_path'];
                        $videoData['terminology_count'] = $responseData['term_count'] ?? ($responseData['unique_term_count'] ?? 0);
                        $videoData['has_terminology'] = true;
                        if(isset($responseData['category_summary'])) {
                            $currentMetadata = $video->terminology_metadata ?? [];
                            $videoData['terminology_metadata'] = array_merge($currentMetadata, ['category_summary' => $responseData['category_summary']]);
                        }
                        $videoData['status'] = 'completed';
                        
                        Log::info('Processing terminology recognition callback - setting status to completed', [
                            'video_id' => $video->id, 
                            'new_status' => 'completed',
                            'response_data' => $responseData
                        ]);
                        
                        if ($log) {
                            $logUpdateData = [
                                'status' => 'completed',
                                'terminology_analysis_completed_at' => $now,
                                'terminology_term_count' => $videoData['terminology_count'],
                                'completed_at' => $now,
                                'progress_percentage' => 100
                            ];
                            if ($log->terminology_analysis_started_at) {
                                $terminologyDuration = $now->diffInSeconds($log->terminology_analysis_started_at);
                                $logUpdateData['terminology_duration_seconds'] = $terminologyDuration;
                            }
                            $log->update($logUpdateData);
                        }
                    }
                }

                if (($video->status === 'transcribed' || $video->status === 'processing_music_terms') && 
                    ((isset($videoData['has_terminology']) && $videoData['has_terminology']) || $video->has_terminology)) {
                    $videoData['status'] = 'completed';
                    Log::info('Forcing status update to completed after terminology recognition', [
                        'video_id' => $video->id,
                        'previous_status' => $video->status,
                        'new_status' => 'completed'
                    ]);
                }

                if ($video->has_terminology && $video->status !== 'completed') {
                    Log::warning('Video with terminology is not in completed state', [
                        'video_id' => $video->id, 
                        'status' => $video->status,
                        'has_terminology' => $video->has_terminology,
                        'has_music_terms' => $video->has_music_terms
                    ]);
                }

                Log::info('[TranscriptionController@updateJobStatus] Data BEFORE $video->update()', ['video_id' => $video->id, 'videoData_to_save_keys' => array_keys($videoData), 'transcript_text_present' => !empty($videoData['transcript_text'])]);
                $video->update($videoData);
                Log::info('[TranscriptionController@updateJobStatus] Data AFTER $video->update() - Video status from DB', ['video_id' => $video->id, 'new_db_status' => $video->fresh()->status, 'transcript_text_in_db_is_empty' => empty($video->fresh()->transcript_text)]);
                
                if (!$log) {
                    TranscriptionLog::create([
                        'job_id' => $jobId,
                        'video_id' => $video->id,
                        'status' => $videoData['status'],
                        'response_data' => $request->response_data,
                        'error_message' => $request->error_message,
                        'completed_at' => $request->completed_at ?? $now,
                    ]);
                }
                
                if (isset($responseData['transcript_path']) && 
                    !$isTerminologyCallback && 
                    $videoData['status'] === 'transcribed') {
                    
                    try {
                        $videoForTerminology = Video::find($video->id);
                        
                        if (!empty($videoForTerminology->transcript_path) && Storage::disk('s3')->exists($videoForTerminology->transcript_path)) {
                            DB::afterCommit(function () use ($video) {
                                Log::info('Automatically triggering terminology recognition after transcription in afterCommit', [
                                    'video_id' => $video->id
                                ]);
                                
                                TerminologyRecognitionJob::dispatch($video)->delay(now()->addSeconds(2));
                            });
                        }
                    } catch (\Exception $e) {
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
            $video = Video::findOrFail($videoId);
            
            Log::info('Dispatching transcription job for video', [
                'video_id' => $videoId
            ]);
            
            \App\Jobs\TranscriptionJob::dispatch($video);
            
            return true;
        } catch (\Exception $e) {
            $errorMessage = 'Exception when dispatching transcription job: ' . $e->getMessage();
            Log::error($errorMessage, [
                'video_id' => $videoId,
                'error' => $e->getMessage()
            ]);
            
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
            $terminologyController = new \App\Http\Controllers\Api\TerminologyController();
            $request = new \Illuminate\Http\Request();
            
            $response = $terminologyController->process($request, $videoId);
            
            if ($response && $response->status() === 200) {
                Log::info('Successfully triggered terminology recognition via controller', [
                    'video_id' => $videoId
                ]);
                return true;
            }
            
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
