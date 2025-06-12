<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalTruefireSegment;
use App\Models\LocalTruefireCourse;
use App\Models\TruefireSegmentProcessing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TruefireSegmentController extends Controller
{
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
                'video_url' => $segment->getSignedUrl(),
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
            'video_url' => $segment->getSignedUrl(),
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

            // Dispatch the transcription job
            \App\Jobs\TruefireSegmentTranscriptionJob::dispatch($processing)->onQueue('transcription');
            
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
                        'audio_url' => asset('storage/' . $relativePath)
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
                    
                    \App\Jobs\TruefireSegmentTranscriptionJob::dispatch($processing, $transcriptionPreset)->onQueue('transcription');
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
                    $processing->update(['transcript_url' => asset('storage/' . $relativePath)]);
                }

                if ($transcriptJsonPath) {
                    $relativePath = str_replace(storage_path('app/public/'), '', $transcriptJsonPath);
                    $processing->update(['transcript_json_url' => asset('storage/' . $relativePath)]);
                }

                if ($subtitlesPath) {
                    $relativePath = str_replace(storage_path('app/public/'), '', $subtitlesPath);
                    $processing->update(['subtitles_url' => asset('storage/' . $relativePath)]);
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
                    $processing->update(['terminology_url' => asset('storage/' . $relativePath)]);
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

            \App\Jobs\TruefireSegmentAudioExtractionJob::dispatch($processing, $jobOptions);
            
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
} 