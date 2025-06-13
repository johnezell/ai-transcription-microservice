<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalTruefireSegment;
use App\Models\LocalTruefireCourse;
use App\Models\TruefireSegmentProcessing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\HostAwareUrlService;

class TruefireSegmentController extends Controller
{
    protected $urlService;

    public function __construct(HostAwareUrlService $urlService)
    {
        $this->urlService = $urlService;
    }

    /**
     * Get the status of a TrueFire course segment processing
     */
    public function getStatus($courseId, $segmentId)
    {
        $segment = LocalTruefireSegment::findOrFail($segmentId);
        $course = LocalTruefireCourse::findOrFail($courseId);
        
        // Get or create processing record
        $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
        
        if (!$processing) {
            // Create initial processing record
            $processing = TruefireSegmentProcessing::create([
                'segment_id' => $segmentId,
                'course_id' => $courseId,
                'status' => 'ready',
                'progress_percentage' => 0
            ]);
        }
        
        // Calculate progress percentage based on status
        $progressPercentage = 0;
        switch ($processing->status) {
            case 'ready':
                $progressPercentage = 0;
                break;
            case 'processing':
                $progressPercentage = 25;
                break;
            case 'audio_extracted':
                $progressPercentage = 40;
                break;
            case 'transcribing':
                $progressPercentage = 60;
                break;
            case 'transcribed':
                $progressPercentage = 75;
                break;
            case 'processing_terminology':
                $progressPercentage = 85;
                break;
            case 'completed':
                $progressPercentage = 100;
                break;
            case 'failed':
                $progressPercentage = 0;
                break;
        }
        
        $response = [
            'success' => true,
            'status' => $processing->status,
            'progress_percentage' => $progressPercentage,
            'segment' => [
                'id' => $segment->id,
                'title' => $segment->title,
                'name' => $segment->name,
                'status' => $processing->status,
                'created_at' => $processing->created_at,
                'updated_at' => $processing->updated_at,
                'has_audio' => $processing->has_audio ?? !empty($processing->audio_path),
                'has_transcript' => !empty($processing->transcript_path),
                'has_terminology' => $processing->has_terminology,
                'error_message' => $processing->error_message,
                // Removed video_url generation from status endpoint - frontend already has this from initial load
            ]
        ];
        
        // Add media details if available
        if ($processing->audio_path) {
            $response['media'] = [
                'audio_size' => $processing->audio_size,
                'audio_duration' => $processing->audio_duration,
                'audio_url' => $processing->audio_url,
            ];
        }
        
        // Add transcript details if available
        if ($processing->transcript_path) {
            $response['transcript'] = [
                'transcript_url' => $processing->transcript_url,
                'transcript_json_url' => $processing->transcript_json_url,
                'transcript_excerpt' => substr($processing->transcript_text ?? '', 0, 200),
            ];
        }
        
        // Add terminology details if available
        if ($processing->has_terminology) {
            $response['terminology'] = [
                'terminology_url' => $processing->terminology_url,
                'terminology_count' => $processing->terminology_count,
                'terminology_metadata' => $processing->terminology_metadata,
            ];
        }
        
        // Add direct paths
        $response['segment']['audio_url'] = $processing->audio_url;
        $response['segment']['transcript_url'] = $processing->transcript_url;
        $response['segment']['subtitles_url'] = $processing->subtitles_url;
        $response['segment']['transcript_json_url'] = $processing->transcript_json_url;
        $response['segment']['transcript_json_api_url'] = "/api/truefire-courses/{$courseId}/segments/{$segmentId}/transcript-json";
        $response['segment']['terminology_url'] = $processing->terminology_url;
        $response['segment']['terminology_json_api_url'] = "/api/truefire-courses/{$courseId}/segments/{$segmentId}/terminology-json";
        $response['segment']['terminology_count'] = $processing->terminology_count;
        $response['segment']['terminology_metadata'] = $processing->terminology_metadata;
        $response['segment']['formatted_duration'] = $processing->formatted_duration;
        $response['segment']['is_processing'] = in_array($processing->status, ['processing', 'transcribing', 'processing_terminology']);
        
        // Add processing timing data for analytics
        $response['segment']['audio_extraction_started_at'] = $processing->audio_extraction_started_at;
        $response['segment']['audio_extraction_completed_at'] = $processing->audio_extraction_completed_at;
        $response['segment']['transcription_started_at'] = $processing->transcription_started_at;
        $response['segment']['transcription_completed_at'] = $processing->transcription_completed_at;
        $response['segment']['terminology_started_at'] = $processing->terminology_started_at;
        $response['segment']['terminology_completed_at'] = $processing->terminology_completed_at;
        
        return response()->json($response);
    }
    
    /**
     * Get a single TrueFire course segment with processing details
     */
    public function show($courseId, $segmentId)
    {
        $segment = LocalTruefireSegment::with('channel', 'course')->findOrFail($segmentId);
        $course = LocalTruefireCourse::findOrFail($courseId);
        
        // Get processing record
        $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
        
        if (!$processing) {
            // Create initial processing record
            $processing = TruefireSegmentProcessing::create([
                'segment_id' => $segmentId,
                'course_id' => $courseId,
                'status' => 'ready',
                'progress_percentage' => 0
            ]);
        }
        
        // Build response with all segment details
        $segmentData = [
            'id' => $segment->id,
            'title' => $segment->title,
            'name' => $segment->name,
            'description' => $segment->description,
            'runtime' => $segment->runtime,
            'video' => $segment->video,
            'channel_id' => $segment->channel_id,
            'channel' => $segment->channel,
            'course' => $segment->course,
            
            // Processing status
            'status' => $processing->status,
            'error_message' => $processing->error_message,
            'is_processing' => in_array($processing->status, ['processing', 'transcribing', 'processing_terminology']),
            
            // Media files
            // Note: video_url intentionally excluded from API response - use frontend routing for video access
            'audio_url' => $processing->audio_url,
            'audio_path' => $processing->audio_path,
            'audio_size' => $processing->audio_size,
            'audio_duration' => $processing->audio_duration,
            'formatted_duration' => $processing->formatted_duration,
            
            // Transcript files
            'transcript_path' => $processing->transcript_path,
            'transcript_url' => $processing->transcript_url,
            'transcript_text' => $processing->transcript_text,
            'transcript_json_url' => $processing->transcript_json_url,
            'transcript_json_api_url' => "/api/truefire-courses/{$courseId}/segments/{$segmentId}/transcript-json",
            'subtitles_url' => $processing->subtitles_url,
            
            // Terminology
            'has_terminology' => $processing->has_terminology,
            'terminology_path' => $processing->terminology_path,
            'terminology_url' => $processing->terminology_url,
            'terminology_json_api_url' => "/api/truefire-courses/{$courseId}/segments/{$segmentId}/terminology-json",
            'terminology_count' => $processing->terminology_count,
            'terminology_metadata' => $processing->terminology_metadata,
            
            // Timestamps
            'created_at' => $processing->created_at,
            'updated_at' => $processing->updated_at,
            
            // Processing timing data for analytics
            'audio_extraction_started_at' => $processing->audio_extraction_started_at,
            'audio_extraction_completed_at' => $processing->audio_extraction_completed_at,
            'transcription_started_at' => $processing->transcription_started_at,
            'transcription_completed_at' => $processing->transcription_completed_at,
            'terminology_started_at' => $processing->terminology_started_at,
            'terminology_completed_at' => $processing->terminology_completed_at,
        ];
        
        return response()->json([
            'success' => true,
            'segment' => $segmentData
        ]);
    }
    
    /**
     * Approve audio extraction for a TrueFire course segment
     */
    public function approveAudioExtraction($courseId, $segmentId, Request $request)
    {
        try {
            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->firstOrFail();

            if ($processing->status !== 'audio_extracted') {
                return response()->json([
                    'success' => false,
                    'message' => 'Audio must be extracted before it can be approved for transcription. Current status: ' . $processing->status
                ], 400);
            }
            
            // Mark as approved (will be picked up by transcription process)
            $processing->update([
                'status' => 'approved_for_transcription',
                'progress_percentage' => 50
            ]);

            // Dispatch the transcription job with priority context
            \App\Jobs\TruefireSegmentTranscriptionJob::dispatch($processing)
                ->onConnection('priority-transcription');
            
            Log::info('TrueFire segment audio extraction approved and transcription job dispatched', [
                'course_id' => $courseId,
                'segment_id' => $segmentId,
                'approved_by' => $request->input('approved_by'),
                'notes' => $request->input('notes')
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Audio extraction approved and transcription process started',
                'segment' => [
                    'id' => $segmentId,
                    'status' => $processing->status,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error approving TrueFire segment audio extraction', [
                'course_id' => $courseId,
                'segment_id' => $segmentId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error approving audio extraction: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get transcript JSON data for a TrueFire course segment
     */
    public function getTranscriptJson($courseId, $segmentId)
    {
        $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
        
        if (!$processing) {
            return response()->json([
                'success' => false,
                'message' => 'Segment processing record not found'
            ], 404);
        }
        
        // First try the database
        if (!empty($processing->transcript_json)) {
            return response()->json($processing->transcript_json);
        }
        
        // Fallback to file if database doesn't have the data
        if (!empty($processing->transcript_path)) {
            $dir = dirname($processing->transcript_path);
            $jsonPath = $dir . '/transcript.json';
            if (file_exists($jsonPath)) {
                $jsonData = json_decode(file_get_contents($jsonPath), true);
                if ($jsonData) {
                    return response()->json($jsonData);
                }
            }
        }
        
        // Last resort: check in storage/app/public/s3/jobs/{segment_id}/ directory
        $jobDir = storage_path("app/public/s3/jobs/{$segmentId}");
        $possiblePaths = [
            $jobDir . '/transcript.json',
            $jobDir . '/whisper_output.json',
            $jobDir . '/transcription.json'
        ];
        
        foreach ($possiblePaths as $jsonPath) {
            if (file_exists($jsonPath)) {
                $jsonData = json_decode(file_get_contents($jsonPath), true);
                if ($jsonData && isset($jsonData['segments'])) {
                    \Log::info('Found transcript JSON in fallback location', [
                        'segment_id' => $segmentId,
                        'path' => $jsonPath
                    ]);
                    return response()->json($jsonData);
                }
            }
        }
        
        // Last resort: generate mock confidence data from transcript text
        if (!empty($processing->transcript_text)) {
            \Log::warning('Using mock confidence data fallback - real transcript JSON not found', [
                'segment_id' => $segmentId,
                'transcript_path' => $processing->transcript_path,
                'database_has_json' => !empty($processing->transcript_json),
                'reason' => 'Real transcript JSON file not found, generating mock data with estimated timing'
            ]);
            
            $mockData = $this->generateMockConfidenceData($processing->transcript_text);
            return response()->json($mockData);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Transcript JSON data not available'
        ], 404);
    }


    
    /**
     * Get terminology JSON data for a TrueFire course segment
     */
    public function getTerminologyJson($courseId, $segmentId)
    {
        $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
        
        if (!$processing) {
            return response()->json([
                'success' => false,
                'message' => 'Segment processing record not found'
            ], 404);
        }
        
        if (empty($processing->terminology_json)) {
            // Fallback to file if database doesn't have the data
            if (!empty($processing->terminology_path)) {
                if (file_exists($processing->terminology_path)) {
                    $jsonData = json_decode(file_get_contents($processing->terminology_path), true);
                    return response()->json($jsonData);
                }
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Terminology data not available'
            ], 404);
        }
        
        return response()->json($processing->terminology_json);
    }
    
    /**
     * Serve audio file for a TrueFire course segment
     */
    public function getAudioFile($courseId, $segmentId)
    {
        $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
        
        if (!$processing) {
            return response()->json([
                'success' => false,
                'message' => 'Segment processing record not found'
            ], 404);
        }
        
        if (empty($processing->audio_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Audio file not available'
            ], 404);
        }
        
        // Check if the audio file exists
        if (!file_exists($processing->audio_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Audio file not found on disk'
            ], 404);
        }
        
        // Get file info
        $fileSize = filesize($processing->audio_path);
        $mimeType = 'audio/wav'; // Most extracted audio files are WAV
        
        Log::info('Serving audio file for TrueFire segment', [
            'segment_id' => $segmentId,
            'course_id' => $courseId,
            'audio_path' => $processing->audio_path,
            'file_size' => $fileSize
        ]);
        
        // Return the file as a streaming response
        return response()->stream(function () use ($processing) {
            $file = fopen($processing->audio_path, 'rb');
            fpassthru($file);
            fclose($file);
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => $fileSize,
            'Content-Disposition' => 'inline; filename="segment_' . $segmentId . '.wav"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=3600'
        ]);
    }
    
    /**
     * Abort/cancel processing for a TrueFire course segment
     */
    public function abortProcessing($courseId, $segmentId)
    {
        try {
            $segment = LocalTruefireSegment::findOrFail($segmentId);
            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
            
            if (!$processing) {
                return response()->json([
                    'success' => false,
                    'message' => 'No processing record found for this segment'
                ], 400);
            }
            
            // Clear any failed jobs related to this segment
            $this->clearRelatedFailedJobs($segmentId);
            
            // Reset the processing record to clean state
            $processing->update([
                'status' => 'ready',
                'progress_percentage' => 0,
                'error_message' => null,
                'audio_path' => null,
                'audio_size' => null,
                'audio_duration' => null,
                'transcript_path' => null,
                'transcript_text' => null,
                'transcript_json' => null,
                'terminology_path' => null,
                'terminology_json' => null,
                'terminology_count' => null,
                'terminology_metadata' => null,
                'has_terminology' => false,
                'audio_extraction_started_at' => null,
                'audio_extraction_completed_at' => null,
                'transcription_started_at' => null,
                'transcription_completed_at' => null,
                'terminology_started_at' => null,
                'terminology_completed_at' => null,
                'audio_extraction_approved' => false,
                'audio_extraction_approved_at' => null,
                'audio_extraction_approved_by' => null,
                'audio_extraction_notes' => null
            ]);
            
            Log::info('TrueFire segment processing aborted and reset', [
                'segment_id' => $segment->id,
                'course_id' => $courseId,
                'previous_status' => $processing->getOriginal('status')
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Processing aborted and segment reset to ready state',
                'segment' => [
                    'id' => $segment->id,
                    'status' => 'ready',
                    'progress_percentage' => 0
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error aborting TrueFire segment processing', [
                'segment_id' => $segmentId,
                'course_id' => $courseId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error aborting processing: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger terminology recognition for a TrueFire course segment - DISABLED
     */
    public function triggerTerminology($courseId, $segmentId)
    {
        return response()->json([
            'success' => false,
            'message' => 'Terminology recognition is currently disabled'
        ], 400);
        
        /*
        try {
            $segment = LocalTruefireSegment::findOrFail($segmentId);
            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
            
            if (!$processing) {
                return response()->json([
                    'success' => false,
                    'message' => 'No processing record found for this segment'
                ], 400);
            }
            
            // Check if transcript is available
            if (empty($processing->transcript_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transcript not available. Please complete transcription first.'
                ], 400);
            }
            
            // Update status and dispatch terminology job
            $processing->update([
                'status' => 'processing_terminology'
            ]);
            
            \App\Jobs\TruefireSegmentTerminologyJob::dispatch($processing);
            
            Log::info('TrueFire segment terminology recognition triggered', [
                'segment_id' => $segment->id,
                'course_id' => $courseId
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Terminology recognition started',
                'segment' => [
                    'id' => $segment->id,
                    'status' => $processing->status
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error triggering TrueFire segment terminology recognition', [
                'segment_id' => $segmentId,
                'course_id' => $courseId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error triggering terminology recognition: ' . $e->getMessage()
            ], 500);
        }
        */
    }

    /**
     * Handle audio extraction completion callback from the audio service
     */
    public function audioExtractionCallback($courseId, $segmentId, Request $request)
    {
        try {
            Log::info('TrueFire segment audio extraction callback received', [
                'course_id' => $courseId,
                'segment_id' => $segmentId,
                'status' => $request->input('status'),
                'request_data' => $request->all()
            ]);

            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
            
            if (!$processing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Processing record not found'
                ], 404);
            }

            $status = $request->input('status');
            $responseData = $request->input('response_data', []);

            if ($status === 'completed') {
                // Audio extraction completed successfully
                $audioPath = $responseData['audio_path'] ?? null;
                $audioDuration = $responseData['duration'] ?? null;
                $audioSize = $responseData['file_size'] ?? null;
                $hasAudio = isset($responseData['has_audio']) ? (bool)$responseData['has_audio'] : ($audioPath ? true : false);
                
                // Get settings from the request (passed from audio extraction job)
                $extractionSettings = $request->input('extraction_settings', []);

                // Set audio extraction completion time when we receive the callback
                $processing->update([
                    'status' => 'audio_extracted',
                    'audio_path' => $audioPath,
                    'audio_duration' => $audioDuration,
                    'audio_size' => $audioSize,
                    'has_audio' => $hasAudio,
                    'audio_extraction_completed_at' => now(),
                    'progress_percentage' => 40
                ]);

                // Generate audio URL
                if ($audioPath) {
                    $relativePath = str_replace(storage_path('app/public/'), '', $audioPath);
                    $processing->update([
                        'audio_url' => $this->urlService->storageUrl($relativePath)
                    ]);
                }

                Log::info('TrueFire segment audio extraction completed', [
                    'segment_id' => $segmentId,
                    'audio_path' => $audioPath,
                    'duration' => $audioDuration,
                    'extraction_settings' => $extractionSettings
                ]);

                // Check if automatic transcription should be triggered
                $followWithTranscription = $extractionSettings['follow_with_transcription'] ?? true;
                $transcriptionPreset = $extractionSettings['transcription_preset'] ?? 'balanced';
                
                if ($followWithTranscription) {
                    // ENHANCED: Early performance video detection to avoid transcription bottlenecks
                    $isPerformanceVideo = $this->detectPerformanceVideoFromAudio($audioPath, $responseData);
                    
                    if ($isPerformanceVideo) {
                        Log::info('Performance video detected - skipping transcription and creating minimal transcript', [
                            'segment_id' => $segmentId,
                            'audio_path' => $audioPath,
                            'detection_reason' => $isPerformanceVideo['reason']
                        ]);
                        
                        // Create minimal transcript for performance video
                        $this->createPerformanceVideoTranscript($processing, $isPerformanceVideo);
                        
                        // Mark as completed with performance video flag
                        $processing->update([
                            'status' => 'completed',
                            'progress_percentage' => 100,
                            'transcript_text' => '[Instrumental Performance]',
                            'transcription_completed_at' => now(),
                            'processing_metadata' => json_encode([
                                'performance_video' => true,
                                'detection_reason' => $isPerformanceVideo['reason'],
                                'skipped_transcription' => true,
                                'auto_generated_transcript' => true
                            ])
                        ]);
                        
                    } else {
                        Log::info('Auto-starting transcription for TrueFire segment', [
                            'segment_id' => $segmentId,
                            'transcription_preset' => $transcriptionPreset,
                            'pipeline_mode' => 'automatic'
                        ]);
                        
                        // Set status to transcribing - transcription_started_at will be set when service sends status update
                        $processing->update([
                            'status' => 'transcribing',
                            'progress_percentage' => 60
                        ]);
                        
                        // Dispatch with priority context
                        \App\Jobs\TruefireSegmentTranscriptionJob::dispatch($processing, $transcriptionPreset)
                            ->onConnection('priority-transcription');
                    }
                } else {
                    Log::info('TrueFire segment audio extraction completed - waiting for manual transcription trigger', [
                        'segment_id' => $segmentId,
                        'audio_path' => $audioPath
                    ]);
                }

            } elseif ($status === 'failed') {
                $errorMessage = $responseData['error'] ?? 'Audio extraction failed';
                $hasAudio = isset($responseData['has_audio']) ? (bool)$responseData['has_audio'] : false;
                
                $processing->update(['has_audio' => $hasAudio]);
                $processing->markAsFailed('Audio extraction failed: ' . $errorMessage);

                Log::error('TrueFire segment audio extraction failed', [
                    'segment_id' => $segmentId,
                    'error' => $errorMessage,
                    'has_audio' => $hasAudio
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Callback processed successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing TrueFire segment audio extraction callback', [
                'course_id' => $courseId,
                'segment_id' => $segmentId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processing callback: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle transcription completion callback from the transcription service
     */
    public function transcriptionCallback($courseId, $segmentId, Request $request)
    {
        try {
            Log::info('TrueFire segment transcription callback received', [
                'course_id' => $courseId,
                'segment_id' => $segmentId,
                'status' => $request->input('status'),
                'request_data' => $request->all()
            ]);

            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
            
            if (!$processing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Processing record not found'
                ], 404);
            }

            $status = $request->input('status');
            $responseData = $request->input('response_data', []);

            if ($status === 'completed') {
                // Transcription completed successfully
                $transcriptPath = $responseData['transcript_path'] ?? null;
                $transcriptJsonPath = $responseData['transcript_json_path'] ?? null;
                $subtitlesPath = $responseData['subtitles_path'] ?? null;
                $transcriptText = $responseData['transcript_text'] ?? null;

                // Set transcription completion time when we receive the callback
                $processing->update([
                    'status' => 'transcribed',
                    'transcript_path' => $transcriptPath,
                    'transcript_text' => $transcriptText,
                    'transcription_completed_at' => now(),
                    'progress_percentage' => 75
                ]);

                // Generate URLs
                if ($transcriptPath) {
                    $relativePath = str_replace(storage_path('app/public/'), '', $transcriptPath);
                    $processing->update(['transcript_url' => $this->urlService->storageUrl($relativePath)]);
                }

                if ($transcriptJsonPath) {
                    $relativePath = str_replace(storage_path('app/public/'), '', $transcriptJsonPath);
                    $processing->update(['transcript_json_url' => $this->urlService->storageUrl($relativePath)]);
                }

                if ($subtitlesPath) {
                    $relativePath = str_replace(storage_path('app/public/'), '', $subtitlesPath);
                    $processing->update(['subtitles_url' => $this->urlService->storageUrl($relativePath)]);
                }

                // Store transcript JSON in database if available
                // First try the provided JSON path, then try to derive it from transcript path
                $jsonFilePaths = [];
                
                if ($transcriptJsonPath) {
                    $jsonFilePaths[] = $transcriptJsonPath;
                }
                
                // Fallback: derive JSON path from transcript path
                if ($transcriptPath) {
                    $dir = dirname($transcriptPath);
                    $basename = basename($transcriptPath, '.txt');
                    $jsonFilePaths[] = $dir . '/' . $basename . '.json';
                    $jsonFilePaths[] = $dir . '/transcript.json';
                }
                
                foreach ($jsonFilePaths as $jsonPath) {
                    if (file_exists($jsonPath)) {
                        try {
                            Log::info('Reading TrueFire transcript JSON from file', [
                                'segment_id' => $segmentId,
                                'json_path' => $jsonPath
                            ]);
                            
                            $jsonContent = json_decode(file_get_contents($jsonPath), true);
                            if ($jsonContent && isset($jsonContent['segments'])) {
                                $processing->update(['transcript_json' => $jsonContent]);
                                
                                Log::info('Successfully stored TrueFire transcript JSON in database', [
                                    'segment_id' => $segmentId,
                                    'segments_count' => count($jsonContent['segments']),
                                    'first_word_timing' => $jsonContent['segments'][0]['words'][0]['start'] ?? 'N/A'
                                ]);
                                break; // Successfully processed, exit loop
                            }
                        } catch (\Exception $e) {
                            Log::error('Failed to read TrueFire transcript JSON', [
                                'segment_id' => $segmentId,
                                'json_path' => $jsonPath,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }

                Log::info('TrueFire segment transcription completed', [
                    'segment_id' => $segmentId,
                    'transcript_path' => $transcriptPath
                ]);

                // Auto-start terminology recognition - DISABLED
                // $processing->startTerminology();
                // \App\Jobs\TruefireSegmentTerminologyJob::dispatch($processing);
                
                // Mark as completed since terminology is disabled
                $processing->update([
                    'status' => 'completed',
                    'progress_percentage' => 100
                ]);

            } elseif ($status === 'failed') {
                $errorMessage = $responseData['error'] ?? 'Transcription failed';
                $processing->markAsFailed('Transcription failed: ' . $errorMessage);

                Log::error('TrueFire segment transcription failed', [
                    'segment_id' => $segmentId,
                    'error' => $errorMessage
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Callback processed successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing TrueFire segment transcription callback', [
                'course_id' => $courseId,
                'segment_id' => $segmentId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processing callback: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle terminology recognition completion callback from the terminology service
     */
    public function terminologyCallback($courseId, $segmentId, Request $request)
    {
        try {
            Log::info('TrueFire segment terminology callback received', [
                'course_id' => $courseId,
                'segment_id' => $segmentId,
                'status' => $request->input('status'),
                'request_data' => $request->all()
            ]);

            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
            
            if (!$processing) {
                return response()->json([
                    'success' => false,
                    'message' => 'Processing record not found'
                ], 404);
            }

            $status = $request->input('status');
            $responseData = $request->input('response_data', []);

            if ($status === 'completed') {
                // Terminology recognition completed successfully
                $terminologyPath = $responseData['terminology_path'] ?? null;
                $terminologyCount = $responseData['term_count'] ?? null;
                $terminologyMetadata = $responseData['metadata'] ?? null;

                $processing->update([
                    'status' => 'completed',
                    'terminology_path' => $terminologyPath,
                    'terminology_count' => $terminologyCount,
                    'terminology_metadata' => $terminologyMetadata,
                    'has_terminology' => true,
                    'terminology_completed_at' => now(),
                    'progress_percentage' => 100
                ]);

                // Generate terminology URL
                if ($terminologyPath) {
                    $relativePath = str_replace(storage_path('app/public/'), '', $terminologyPath);
                    $processing->update(['terminology_url' => $this->urlService->storageUrl($relativePath)]);
                }

                Log::info('TrueFire segment terminology recognition completed', [
                    'segment_id' => $segmentId,
                    'terminology_path' => $terminologyPath,
                    'term_count' => $terminologyCount
                ]);

            } elseif ($status === 'failed') {
                // Terminology failed, but mark as completed anyway (terminology is optional)
                $errorMessage = $responseData['error'] ?? 'Terminology recognition failed';
                
                $processing->update([
                    'status' => 'completed',
                    'terminology_error' => $errorMessage,
                    'terminology_completed_at' => now(),
                    'progress_percentage' => 100
                ]);

                Log::warning('TrueFire segment terminology recognition failed but marking as completed', [
                    'segment_id' => $segmentId,
                    'error' => $errorMessage
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Callback processed successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing TrueFire segment terminology callback', [
                'course_id' => $courseId,
                'segment_id' => $segmentId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processing callback: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate mock confidence data from transcript text for demonstration purposes
     * NOTE: This should not be needed if the transcription process is working correctly
     */
    protected function generateMockConfidenceData($transcriptText)
    {
        $words = preg_split('/\s+/', trim($transcriptText));
        $mockSegments = [];
        $currentTime = 0.0; // Start from 0 for mock data
        $wordsPerSegment = 20; // Group words into segments
        
        for ($i = 0; $i < count($words); $i += $wordsPerSegment) {
            $segmentWords = array_slice($words, $i, $wordsPerSegment);
            $segmentText = implode(' ', $segmentWords);
            
            // Calculate segment duration based on word count (average 2 words per second)
            $segmentDuration = count($segmentWords) * 0.5;
            
            $mockWords = [];
            $wordStartTime = $currentTime;
            
            foreach ($segmentWords as $word) {
                // Generate realistic confidence scores (mostly high, some variation)
                $confidence = min(0.99, max(0.65, 0.85 + (rand(-15, 15) / 100)));
                
                // Calculate word duration (average 0.5 seconds per word)
                $wordDuration = 0.4 + (rand(-10, 20) / 100);
                
                $mockWords[] = [
                    'word' => trim($word, '.,!?;:"()[]'),
                    'start' => round($wordStartTime, 2),
                    'end' => round($wordStartTime + $wordDuration, 2),
                    'probability' => round($confidence, 3)
                ];
                
                $wordStartTime += $wordDuration;
            }
            
            $mockSegments[] = [
                'id' => $i / $wordsPerSegment,
                'seek' => intval($currentTime * 100),
                'start' => round($currentTime, 2),
                'end' => round($currentTime + $segmentDuration, 2),
                'text' => $segmentText,
                'temperature' => 0.0,
                'avg_logprob' => -0.2 - (rand(0, 20) / 100),
                'compression_ratio' => 1.5 + (rand(-20, 20) / 100),
                'no_speech_prob' => 0.01 + (rand(0, 5) / 1000),
                'words' => $mockWords
            ];
            
            $currentTime += $segmentDuration;
        }
        
        return [
            'task' => 'transcribe',
            'language' => 'en',
            'duration' => round($currentTime, 2),
            'text' => $transcriptText,
            'segments' => $mockSegments
        ];
    }

    /**
     * Clear failed jobs related to a specific segment
     */
    protected function clearRelatedFailedJobs($segmentId)
    {
        try {
            // Clear failed jobs that contain this segment ID
            $failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')
                ->where('payload', 'like', '%TruefireSegment%')
                ->where(function($query) use ($segmentId) {
                    $query->where('payload', 'like', "%segment_id\":\"{$segmentId}\"%")
                          ->orWhere('payload', 'like', "%segment_id\":{$segmentId}%");
                })
                ->get();

            foreach ($failedJobs as $job) {
                \Illuminate\Support\Facades\DB::table('failed_jobs')
                    ->where('id', $job->id)
                    ->delete();
                    
                Log::info('Cleared failed job for segment', [
                    'segment_id' => $segmentId,
                    'job_id' => $job->id
                ]);
            }
            
            // Also try to remove any pending jobs from the queue
            $pendingJobs = \Illuminate\Support\Facades\DB::table('jobs')
                ->where('payload', 'like', '%TruefireSegment%')
                ->where(function($query) use ($segmentId) {
                    $query->where('payload', 'like', "%segment_id\":\"{$segmentId}\"%")
                          ->orWhere('payload', 'like', "%segment_id\":{$segmentId}%");
                })
                ->get();

            foreach ($pendingJobs as $job) {
                \Illuminate\Support\Facades\DB::table('jobs')
                    ->where('id', $job->id)
                    ->delete();
                    
                Log::info('Cleared pending job for segment', [
                    'segment_id' => $segmentId,
                    'job_id' => $job->id
                ]);
            }

        } catch (\Exception $e) {
            Log::warning('Failed to clear jobs for segment', [
                'segment_id' => $segmentId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Redo complete processing for a TrueFire course segment
     */
    public function redoProcessing($courseId, $segmentId, Request $request)
    {
        $validated = $request->validate([
            'force_reextraction' => 'sometimes|boolean',
            'overwrite_existing' => 'sometimes|boolean',
            'use_intelligent_detection' => 'sometimes|boolean',
            'audio_quality' => 'sometimes|string|in:fast,balanced,high,premium',
            'transcription_preset' => 'sometimes|string|in:fast,balanced,high,premium',
        ]);

        try {
            $segment = LocalTruefireSegment::findOrFail($segmentId);
            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
            
            if (!$processing) {
                return response()->json([
                    'success' => false,
                    'message' => 'No processing record found for this segment'
                ], 400);
            }

            // Validate that the segment is completed (for safety)
            if ($processing->status !== 'completed' && $processing->status !== 'failed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only redo processing for completed or failed segments. Current status: ' . $processing->status
                ], 400);
            }

            $useIntelligentDetection = $validated['use_intelligent_detection'] ?? false;

            Log::info('Starting redo processing for TrueFire segment', [
                'segment_id' => $segmentId,
                'course_id' => $courseId,
                'previous_status' => $processing->status,
                'force_reextraction' => $validated['force_reextraction'] ?? false,
                'overwrite_existing' => $validated['overwrite_existing'] ?? false,
                'use_intelligent_detection' => $useIntelligentDetection,
                'audio_quality' => $useIntelligentDetection ? 'intelligent_detection' : ($validated['audio_quality'] ?? 'balanced'),
                'transcription_preset' => $useIntelligentDetection ? 'intelligent_detection' : ($validated['transcription_preset'] ?? 'balanced')
            ]);

            // Clear any existing failed/pending jobs
            $this->clearRelatedFailedJobs($segmentId);

            // Reset the processing record to a clean state with new options
            $processingMetadata = [
                'redo_processing' => true,
                'redo_timestamp' => now()->toISOString(),
                'use_intelligent_detection' => $useIntelligentDetection
            ];

            if ($useIntelligentDetection) {
                $processingMetadata['detection_mode'] = 'intelligent';
                $processingMetadata['note'] = 'Using intelligent detection for optimal audio and transcription settings';
            } else {
                $processingMetadata['audio_quality'] = $validated['audio_quality'] ?? 'balanced';
                $processingMetadata['transcription_preset'] = $validated['transcription_preset'] ?? 'balanced';
                $processingMetadata['detection_mode'] = 'manual';
            }

            $processing->update([
                'status' => 'processing',
                'progress_percentage' => 0,
                'error_message' => null,
                'started_at' => now(),
                'priority' => 'high', // Set high priority for user-initiated redo operations
                'processing_metadata' => json_encode($processingMetadata),
                
                // Clear audio extraction data
                'audio_extraction_started_at' => null,
                'audio_extraction_completed_at' => null,
                'audio_path' => null,
                'audio_url' => null,
                'audio_duration' => null,
                'audio_size' => null,
                'has_audio' => false,
                
                // Clear transcription data
                'transcription_started_at' => null,
                'transcription_completed_at' => null,
                'transcript_path' => null,
                'transcript_url' => null,
                'transcript_text' => null,
                'transcript_json_url' => null,
                'subtitles_url' => null,
                
                // Clear terminology data
                'terminology_started_at' => null,
                'terminology_completed_at' => null,
                'terminology_path' => null,
                'terminology_url' => null,
                'terminology_count' => null,
                'terminology_metadata' => null,
                'terminology_error' => null,
                'has_terminology' => false,
                
                // Clear approval data
                'audio_extraction_approved' => false,
                'audio_extraction_approved_at' => null,
                'audio_extraction_approved_by' => null,
                'audio_extraction_notes' => null
            ]);

            // Start processing - audio_extraction_started_at will be set when service sends status update
            $processing->update([
                'status' => 'processing',
                'progress_percentage' => 25
            ]);
            
            $jobOptions = [
                'force_reextraction' => $validated['force_reextraction'] ?? true,
                'overwrite_existing' => $validated['overwrite_existing'] ?? true,
                'use_intelligent_detection' => $useIntelligentDetection
            ];

            if (!$useIntelligentDetection) {
                // Only pass specific presets if not using intelligent detection
                $jobOptions['quality_level'] = $validated['audio_quality'] ?? 'balanced';
                $jobOptions['transcription_preset'] = $validated['transcription_preset'] ?? 'balanced';
            }

            // Dispatch job with priority context
            \App\Jobs\TruefireSegmentAudioExtractionJob::dispatch($processing, $jobOptions)
                ->onConnection('priority-audio-extraction');
            
            Log::info('TrueFire segment redo processing started', [
                'segment_id' => $segment->id,
                'course_id' => $courseId,
                'job_dispatched' => 'TruefireSegmentAudioExtractionJob',
                'detection_mode' => $useIntelligentDetection ? 'intelligent' : 'manual',
                'job_options' => $jobOptions
            ]);

            $responseMessage = $useIntelligentDetection 
                ? 'Redo processing started successfully with intelligent detection for optimal settings. All existing data will be overwritten.'
                : 'Redo processing started successfully with selected presets. All existing data will be overwritten.';

            $responseOptions = $useIntelligentDetection 
                ? ['detection_mode' => 'intelligent', 'note' => 'System will automatically select optimal settings']
                : [
                    'detection_mode' => 'manual',
                    'audio_quality' => $validated['audio_quality'] ?? 'balanced',
                    'transcription_preset' => $validated['transcription_preset'] ?? 'balanced'
                ];
            
            return response()->json([
                'success' => true,
                'message' => $responseMessage,
                'segment' => [
                    'id' => $segment->id,
                    'status' => 'processing',
                    'progress_percentage' => 0,
                    'started_at' => $processing->started_at
                ],
                'options' => $responseOptions
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error starting redo processing for TrueFire segment', [
                'segment_id' => $segmentId,
                'course_id' => $courseId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error starting redo processing: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Detect if an audio file likely represents a performance video with minimal speech
     * This prevents unnecessary transcription attempts that would fail with empty replies
     */
    private function detectPerformanceVideoFromAudio($audioPath, $responseData = [])
    {
        try {
            if (!file_exists($audioPath)) {
                return false;
            }
            
            // Get basic file info
            $fileSize = filesize($audioPath);
            $duration = $responseData['duration'] ?? null;
            
            // If we have duration from audio extraction response, use it
            if (!$duration) {
                // Try to get duration using ffprobe if available
                $duration = $this->getAudioDuration($audioPath);
            }
            
            Log::info('Analyzing audio for performance video detection', [
                'audio_path' => $audioPath,
                'file_size' => $fileSize,
                'duration' => $duration
            ]);
            
            // Detection criteria
            
            // 1. Very small files (less than 100KB) are likely minimal content
            if ($fileSize < 100 * 1024) {
                return [
                    'is_performance' => true,
                    'reason' => 'very_small_file',
                    'details' => "File size: " . round($fileSize / 1024, 1) . "KB"
                ];
            }
            
            // 2. Very short duration (less than 10 seconds) likely minimal content
            if ($duration && $duration < 10) {
                return [
                    'is_performance' => true,
                    'reason' => 'very_short_duration',
                    'details' => "Duration: {$duration} seconds"
                ];
            }
            
            // 3. Low bitrate might indicate compressed/minimal content
            if ($duration && $duration > 0) {
                $bitrate = ($fileSize * 8) / $duration; // bits per second
                $kbps = $bitrate / 1000;
                
                // If bitrate is extremely low (less than 32 kbps), might be minimal content
                if ($kbps < 32) {
                    return [
                        'is_performance' => true,
                        'reason' => 'very_low_bitrate',
                        'details' => "Bitrate: " . round($kbps, 1) . " kbps"
                    ];
                }
            }
            
            // 4. Check for mostly silence using ffmpeg if available
            $silenceRatio = $this->checkAudioSilenceRatio($audioPath);
            if ($silenceRatio !== null && $silenceRatio > 0.8) {
                return [
                    'is_performance' => true,
                    'reason' => 'mostly_silence',
                    'details' => "Silence ratio: " . round($silenceRatio * 100, 1) . "%"
                ];
            }
            
            // Not detected as performance video
            return false;
            
        } catch (\Exception $e) {
            Log::warning('Performance video detection failed, proceeding with transcription', [
                'audio_path' => $audioPath,
                'error' => $e->getMessage()
            ]);
            
            // If detection fails, proceed with transcription to be safe
            return false;
        }
    }
    
    /**
     * Get audio duration using ffprobe if available
     */
    private function getAudioDuration($audioPath)
    {
        try {
            // Try to use ffprobe to get duration
            $command = "ffprobe -v quiet -show_entries format=duration -of csv=p=0 " . escapeshellarg($audioPath);
            $output = shell_exec($command);
            
            if ($output) {
                $duration = floatval(trim($output));
                if ($duration > 0) {
                    return $duration;
                }
            }
        } catch (\Exception $e) {
            Log::debug('Could not get audio duration with ffprobe', [
                'audio_path' => $audioPath,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }
    
    /**
     * Check silence ratio in audio file using ffmpeg if available
     */
    private function checkAudioSilenceRatio($audioPath)
    {
        try {
            // Use ffmpeg to detect silence
            // -af silencedetect=noise=-30dB:duration=0.5 detects silence of 0.5 seconds at -30dB threshold
            $command = "ffmpeg -i " . escapeshellarg($audioPath) . " -af silencedetect=noise=-30dB:duration=0.5 -f null - 2>&1";
            $output = shell_exec($command);
            
            if (!$output) {
                return null;
            }
            
            // Parse silence detection output
            preg_match_all('/silence_duration: ([\d.]+)/', $output, $matches);
            if (!empty($matches[1])) {
                $totalSilence = array_sum(array_map('floatval', $matches[1]));
                
                // Get total duration
                preg_match('/Duration: (\d{2}):(\d{2}):([\d.]+)/', $output, $durationMatch);
                if (!empty($durationMatch)) {
                    $totalDuration = ($durationMatch[1] * 3600) + ($durationMatch[2] * 60) + floatval($durationMatch[3]);
                    if ($totalDuration > 0) {
                        return $totalSilence / $totalDuration;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('Could not analyze audio silence ratio', [
                'audio_path' => $audioPath,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }
    
    /**
     * Create minimal transcript JSON for performance videos
     */
    private function createPerformanceVideoTranscript($processing, $detectionInfo)
    {
        $transcriptData = [
            "text" => "[Instrumental Performance]",
            "language" => "en",
            "duration" => $processing->audio_duration ?? 0,
            "segments" => [
                [
                    "id" => 0,
                    "seek" => 0,
                    "start" => 0.0,
                    "end" => $processing->audio_duration ?? 0,
                    "text" => "[Instrumental Performance]",
                    "tokens" => [50364, 50365, 50366],
                    "temperature" => 0.0,
                    "avg_logprob" => -0.1,
                    "compression_ratio" => 1.0,
                    "no_speech_prob" => 0.95,
                    "words" => [
                        [
                            "word" => "[Instrumental",
                            "start" => 0.0,
                            "end" => 1.0,
                            "probability" => 1.0
                        ],
                        [
                            "word" => "Performance]",
                            "start" => 1.0,
                            "end" => 2.0,
                            "probability" => 1.0
                        ]
                    ]
                ]
            ],
            "word_segments" => [
                [
                    "word" => "[Instrumental",
                    "start" => 0.0,
                    "end" => 1.0,
                    "score" => 1.0
                ],
                [
                    "word" => "Performance]",
                    "start" => 1.0,
                    "end" => 2.0,
                    "score" => 1.0
                ]
            ],
            "quality_metrics" => [
                "overall_quality_score" => 0.95,
                "speech_activity" => [
                    "speech_ratio" => 0.05,
                    "non_speech_ratio" => 0.95,
                    "speech_segments" => 1,
                    "activity_pattern" => "performance"
                ]
            ],
            "performance_video_metadata" => [
                "detection_method" => "early_audio_analysis",
                "detection_reason" => $detectionInfo['reason'] ?? 'Unknown',
                "detection_criteria" => $detectionInfo['criteria'] ?? [],
                "file_characteristics" => $detectionInfo['file_characteristics'] ?? []
            ]
        ];

        return $transcriptData;
    }

    /**
     * Get available LLM models from Ollama service
     */
    public function getAvailableModels()
    {
        try {
            $ollamaUrl = env('TRANSCRIPTION_SERVICE_URL', 'http://transcription-service:5000');
            $response = file_get_contents($ollamaUrl . '/get-available-models');
            
            if ($response === false) {
                throw new \Exception('Failed to fetch models from transcription service');
            }
            
            $models = json_decode($response, true);
            
            return response()->json([
                'success' => true,
                'models' => $models['models'] ?? []
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching available models', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch available models: ' . $e->getMessage(),
                'models' => []
            ], 500);
        }
    }

    /**
     * Test guitar term evaluation with a specific model using pure LLM approach
     */
    public function testGuitarTermEvaluation($courseId, $segmentId, Request $request)
    {
        try {
            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->firstOrFail();
            
            if (empty($processing->transcript_json)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No transcript data available for testing'
                ], 400);
            }
            
            $model = $request->input('model', 'llama3:latest');
            $confidenceThreshold = $request->input('confidence_threshold', 0.75);
            
            // Call transcription service for pure LLM testing
            $transcriptionServiceUrl = env('TRANSCRIPTION_SERVICE_URL', 'http://transcription-service:5000');
            $payload = [
                'segment_id' => $segmentId,  // Pass segment ID for pure LLM testing
                'model' => $model,
                'confidence_threshold' => $confidenceThreshold
            ];
            
            $ch = curl_init($transcriptionServiceUrl . '/test-guitar-term-model');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 1 minute timeout
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                throw new \Exception("CURL error: $error");
            }
            
            if ($httpCode !== 200) {
                throw new \Exception("HTTP error: $httpCode");
            }
            
            $result = json_decode($response, true);
            
            if (!$result) {
                throw new \Exception('Invalid response from transcription service');
            }
            
            Log::info('Pure LLM guitar term evaluation test completed', [
                'segment_id' => $segmentId,
                'model' => $model,
                'terms_found' => $result['guitar_term_evaluation']['musical_terms_found'] ?? 0,
                'llm_queries' => $result['guitar_term_evaluation']['llm_queries_made'] ?? 0,
                'evaluation_mode' => $result['guitar_term_evaluation']['evaluation_mode'] ?? 'unknown'
            ]);
            
            return response()->json([
                'success' => true,
                'model' => $model,
                'confidence_threshold' => $confidenceThreshold,
                'results' => $result,
                'test_metadata' => [
                    'segment_id' => $segmentId,
                    'course_id' => $courseId,
                    'tested_at' => now()->toISOString(),
                    'evaluation_mode' => 'pure_llm'
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error testing pure LLM guitar term evaluation', [
                'segment_id' => $segmentId,
                'model' => $request->input('model'),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error testing model: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Compare multiple models for guitar term evaluation using pure LLM approach
     */
    public function compareModels($courseId, $segmentId, Request $request)
    {
        try {
            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->firstOrFail();
            
            if (empty($processing->transcript_json)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No transcript data available for comparison'
                ], 400);
            }
            
            $models = $request->input('models', ['llama3:latest']);
            $confidenceThreshold = $request->input('confidence_threshold', 0.75);
            
            if (!is_array($models) || empty($models)) {
                return response()->json([
                    'success' => false,
                    'message' => 'At least one model must be specified for comparison'
                ], 400);
            }
            
            // Call transcription service for pure LLM comparison
            $transcriptionServiceUrl = env('TRANSCRIPTION_SERVICE_URL', 'http://transcription-service:5000');
            $payload = [
                'segment_id' => $segmentId,  // Pass segment ID for pure LLM testing
                'models' => $models,
                'confidence_threshold' => $confidenceThreshold
            ];
            
            $ch = curl_init($transcriptionServiceUrl . '/compare-guitar-term-models');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minute timeout for multiple models
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                throw new \Exception("CURL error: $error");
            }
            
            if ($httpCode !== 200) {
                throw new \Exception("HTTP error: $httpCode");
            }
            
            $result = json_decode($response, true);
            
            if (!$result) {
                throw new \Exception('Invalid response from transcription service');
            }
            
            // Calculate additional comparison metrics
            $comparison = $this->calculateModelComparison($result['results'] ?? []);
            
            Log::info('Pure LLM model comparison completed', [
                'segment_id' => $segmentId,
                'models_tested' => $result['models_tested'] ?? [],
                'models_completed' => $result['timing']['models_completed'] ?? 0,
                'models_failed' => $result['timing']['models_failed'] ?? 0,
                'total_time' => $result['timing']['total_comparison_time'] ?? 0
            ]);
            
            return response()->json([
                'success' => true,
                'models_tested' => $models,
                'confidence_threshold' => $confidenceThreshold,
                'results' => $result['results'] ?? [],
                'errors' => $result['errors'] ?? [],
                'comparison' => $comparison,
                'comparison_analysis' => $result['comparison_analysis'] ?? null,
                'timing' => $result['timing'] ?? [],
                'test_metadata' => [
                    'segment_id' => $segmentId,
                    'course_id' => $courseId,
                    'compared_at' => now()->toISOString(),
                    'total_models' => count($models),
                    'evaluation_mode' => 'pure_llm'
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error comparing pure LLM models', [
                'segment_id' => $segmentId,
                'models' => $request->input('models'),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error comparing models: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate comparison metrics between different model results
     */
    private function calculateModelComparison($results)
    {
        if (empty($results)) {
            return null;
        }
        
        $comparison = [
            'models' => [],
            'best_performer' => null,
            'agreement_analysis' => [],
            'performance_summary' => []
        ];
        
        // Analyze each model's performance
        foreach ($results as $model => $metrics) {
            $guitarEval = $metrics['guitar_term_evaluation'] ?? [];
            $enhancedTerms = $guitarEval['enhanced_terms'] ?? [];
            
            $modelMetrics = [
                'model' => $model,
                'guitar_terms_found' => $guitarEval['musical_terms_found'] ?? 0,
                'total_words_enhanced' => $guitarEval['llm_queries_made'] ?? 0,
                'llm_queries_made' => $guitarEval['llm_queries_made'] ?? 0,
                'llm_successful_responses' => $guitarEval['llm_successful_responses'] ?? 0,
                'response_time' => $guitarEval['processing_time'] ?? 0,
                'enhanced_terms' => $enhancedTerms,
                'unique_terms' => array_unique(array_column($enhancedTerms, 'word')),
                'accuracy_score' => $this->calculateAccuracyScore($guitarEval),
                'efficiency_score' => $this->calculateEfficiencyScore($guitarEval)
            ];
            
            $comparison['models'][$model] = $modelMetrics;
        }
        
        // Find best performer
        $bestScore = 0;
        $bestModel = null;
        
        foreach ($comparison['models'] as $model => $metrics) {
            $overallScore = ($metrics['accuracy_score'] * 0.7) + ($metrics['efficiency_score'] * 0.3);
            if ($overallScore > $bestScore) {
                $bestScore = $overallScore;
                $bestModel = $model;
            }
        }
        
        $comparison['best_performer'] = [
            'model' => $bestModel,
            'score' => $bestScore,
            'metrics' => $comparison['models'][$bestModel] ?? null
        ];
        
        // Agreement analysis
        $comparison['agreement_analysis'] = $this->analyzeModelAgreement($comparison['models']);
        
        return $comparison;
    }

    /**
     * Calculate accuracy score for a model
     */
    private function calculateAccuracyScore($guitarEval)
    {
        $termsFound = $guitarEval['musical_terms_found'] ?? 0;
        $totalQueries = $guitarEval['llm_queries_made'] ?? 1;
        $successRate = $guitarEval['llm_successful_responses'] ?? 0;
        
        // Weighted score: terms found (60%) + success rate (40%)
        $termScore = min($termsFound / 10, 1.0); // Normalize to 0-1 (assume 10+ terms is excellent)
        $queryScore = $totalQueries > 0 ? $successRate / $totalQueries : 0;
        
        return ($termScore * 0.6) + ($queryScore * 0.4);
    }

    /**
     * Calculate efficiency score for a model
     */
    private function calculateEfficiencyScore($guitarEval)
    {
        $processingTime = $guitarEval['processing_time'] ?? 1;
        $queries = $guitarEval['llm_queries_made'] ?? 1;
        
        // Efficiency = terms found per second
        $termsPerSecond = ($guitarEval['musical_terms_found'] ?? 0) / max($processingTime, 0.1);
        $queriesPerSecond = $queries / max($processingTime, 0.1);
        
        // Normalize to 0-1 scale (assume 5 terms/second is excellent)
        $efficiency = min($termsPerSecond / 5, 1.0);
        
        return $efficiency;
    }

    /**
     * Analyze agreement between different models
     */
    private function analyzeModelAgreement($modelResults)
    {
        $allTerms = [];
        $modelTerms = [];
        
        // Collect all terms found by each model
        foreach ($modelResults as $model => $metrics) {
            $terms = array_map('strtolower', $metrics['unique_terms']);
            $modelTerms[$model] = $terms;
            $allTerms = array_merge($allTerms, $terms);
        }
        
        $allTerms = array_unique($allTerms);
        $agreementMatrix = [];
        
        // Calculate agreement for each term
        foreach ($allTerms as $term) {
            $foundBy = [];
            foreach ($modelTerms as $model => $terms) {
                if (in_array($term, $terms)) {
                    $foundBy[] = $model;
                }
            }
            
            $agreementMatrix[$term] = [
                'found_by' => $foundBy,
                'agreement_count' => count($foundBy),
                'agreement_percentage' => (count($foundBy) / count($modelResults)) * 100
            ];
        }
        
        // Calculate overall agreement metrics
        $highAgreement = array_filter($agreementMatrix, function($item) {
            return $item['agreement_percentage'] >= 75;
        });
        
        $lowAgreement = array_filter($agreementMatrix, function($item) {
            return $item['agreement_percentage'] < 50;
        });
        
        return [
            'total_unique_terms' => count($allTerms),
            'high_agreement_terms' => count($highAgreement),
            'low_agreement_terms' => count($lowAgreement),
            'agreement_details' => $agreementMatrix,
            'consensus_terms' => array_keys($highAgreement),
            'disputed_terms' => array_keys($lowAgreement)
        ];
    }

    /**
     * Get segment transcript data for transcription service
     */
    public function getSegmentTranscriptData($segmentId)
    {
        try {
            $processing = TruefireSegmentProcessing::where('segment_id', $segmentId)->first();
            
            if (!$processing || empty($processing->transcript_json)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Segment not found or no transcript available'
                ], 404);
            }
            
            $transcriptData = json_decode($processing->transcript_json, true);
            
            if (!$transcriptData || !isset($transcriptData['word_segments'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid transcript data - no word segments found'
                ], 400);
            }
            
            return response()->json([
                'success' => true,
                'segment_id' => $segmentId,
                'transcription_result' => $transcriptData,
                'segment_info' => [
                    'course_id' => $processing->course_id,
                    'status' => $processing->status,
                    'updated_at' => $processing->updated_at
                ]
            ]);
            
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving segment data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 