<?php

namespace App\Http\Controllers;

use App\Models\LocalTruefireCourse;
use App\Models\TranscriptionLog;
use App\Jobs\TranscriptionTestJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TranscriptionTestController extends Controller
{
    /**
     * Get available segments for transcription testing
     * 
     * @param LocalTruefireCourse $truefireCourse
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAvailableSegments(LocalTruefireCourse $truefireCourse)
    {
        try {
            // Load course with segments to find available WAV files
            $course = $truefireCourse->load(['channels.segments' => function ($query) {
                $query->withVideo(); // Only load segments with valid video fields
            }]);

            // Collect all segments from channels
            $allSegments = collect();
            foreach ($course->channels as $channel) {
                $allSegments = $allSegments->merge($channel->segments);
            }

            if ($allSegments->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No segments with valid video fields found for this course.',
                    'available_segments' => []
                ], 404);
            }

            // Check for WAV files availability
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            $disk = 'd_drive'; // Always use d_drive for TrueFire courses
            $availableSegments = [];

            foreach ($allSegments as $segment) {
                // Check for WAV file existence
                $wavFilename = "{$segment->id}.wav";
                $wavFilePath = "{$courseDir}/{$wavFilename}";
                
                if (Storage::disk($disk)->exists($wavFilePath)) {
                    $availableSegments[] = [
                        'segment_id' => $segment->id,
                        'channel_id' => $segment->channel_id,
                        'channel_name' => $segment->channel->name ?? $segment->channel->title ?? "Channel #{$segment->channel_id}",
                        'title' => $segment->title ?? "Segment #{$segment->id}",
                        'wav_file' => $wavFilename,
                        'wav_file_path' => $wavFilePath,
                        'file_size' => Storage::disk($disk)->size($wavFilePath),
                        'last_modified' => Storage::disk($disk)->lastModified($wavFilePath)
                    ];
                }
            }

            Log::info('Available segments for transcription testing retrieved', [
                'course_id' => $truefireCourse->id,
                'total_segments' => $allSegments->count(),
                'available_segments' => count($availableSegments)
            ]);

            return response()->json([
                'success' => true,
                'course_id' => $truefireCourse->id,
                'course_title' => $truefireCourse->title ?? "Course #{$truefireCourse->id}",
                'total_segments' => $allSegments->count(),
                'available_segments_count' => count($availableSegments),
                'available_segments' => $availableSegments
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting available segments for transcription testing', [
                'course_id' => $truefireCourse->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving available segments for transcription testing',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Test transcription on a specific segment
     * 
     * @param LocalTruefireCourse $truefireCourse
     * @param int $segmentId
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testTranscription(LocalTruefireCourse $truefireCourse, $segmentId, Request $request)
    {
        try {
            // Validate the request
            $validated = $request->validate([
                'preset' => 'sometimes|string|in:fast,balanced,high,premium',
                'transcription_settings' => 'sometimes|array',
                'transcription_settings.model' => 'sometimes|string',
                'transcription_settings.language' => 'sometimes|string',
                'transcription_settings.temperature' => 'sometimes|numeric|min:0|max:1',
                'transcription_settings.response_format' => 'sometimes|string|in:json,text,srt,verbose_json,vtt',
                'transcription_settings.timestamp_granularities' => 'sometimes|array',
                'transcription_settings.timestamp_granularities.*' => 'string|in:word,segment'
            ]);

            // Load the course with segments to find the requested segment
            $course = $truefireCourse->load(['channels.segments' => function ($query) {
                $query->withVideo(); // Only load segments with valid video fields
            }]);

            // Find the specific segment
            $segment = null;
            foreach ($course->channels as $channel) {
                $foundSegment = $channel->segments->where('id', $segmentId)->first();
                if ($foundSegment) {
                    $segment = $foundSegment;
                    break;
                }
            }

            if (!$segment) {
                return response()->json([
                    'success' => false,
                    'message' => "Segment {$segmentId} not found in this course or does not have a valid video field."
                ], 404);
            }

            // Check if WAV file exists
            $courseDir = "truefire-courses/{$truefireCourse->id}";
            $wavFilename = "{$segment->id}.wav";
            $wavFilePath = "{$courseDir}/{$wavFilename}";
            $disk = 'd_drive'; // Always use d_drive for TrueFire courses
            
            if (!Storage::disk($disk)->exists($wavFilePath)) {
                return response()->json([
                    'success' => false,
                    'message' => "WAV file for segment {$segmentId} not found. Please extract audio first.",
                    'required_file' => $wavFilePath
                ], 404);
            }

            // Get transcription preset (default to course preset or balanced)
            $preset = $validated['preset'] ?? $truefireCourse->getTranscriptionPreset() ?? 'balanced';
            
            // Set default transcription settings
            $transcriptionSettings = $validated['transcription_settings'] ?? [
                'model' => 'whisper-1',
                'language' => 'en',
                'temperature' => 0.0,
                'response_format' => 'verbose_json',
                'timestamp_granularities' => ['segment']
            ];

            // Generate unique test ID
            $testId = 'transcription_test_' . $truefireCourse->id . '_' . $segmentId . '_' . time() . '_' . uniqid();

            Log::info('TranscriptionTestController dispatching TranscriptionTestJob', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'wav_file_path' => $wavFilePath,
                'wav_filename' => $wavFilename,
                'preset' => $preset,
                'transcription_settings' => $transcriptionSettings,
                'test_id' => $testId,
                'workflow_step' => 'controller_job_dispatch'
            ]);

            // Dispatch transcription test job
            TranscriptionTestJob::dispatch(
                $wavFilePath,
                $wavFilename,
                $preset,
                $transcriptionSettings,
                $segmentId,
                $truefireCourse->id,
                $testId
            );

            Log::info('Transcription test job queued successfully', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'preset' => $preset,
                'transcription_settings' => $transcriptionSettings,
                'wav_file_path' => $wavFilePath,
                'test_id' => $testId,
                'workflow_step' => 'controller_job_queued_success'
            ]);

            return response()->json([
                'success' => true,
                'message' => "Transcription test queued for segment {$segmentId}",
                'test_id' => $testId,
                'segment' => [
                    'id' => $segment->id,
                    'title' => $segment->title ?? "Segment #{$segment->id}",
                    'wav_file' => $wavFilename
                ],
                'test_parameters' => [
                    'preset' => $preset,
                    'transcription_settings' => $transcriptionSettings
                ],
                'background_processing' => true,
                'workflow_info' => [
                    'next_step' => 'Job will be processed by queue worker',
                    'expected_logs' => 'Check Laravel logs for workflow_step progress',
                    'test_id' => $testId
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error queuing transcription test', [
                'course_id' => $truefireCourse->id,
                'segment_id' => $segmentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while queuing the transcription test.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get transcription test results
     * 
     * @param string $testId
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTestResults($testId, Request $request)
    {
        try {
            // Build query for transcription test results
            $query = TranscriptionLog::where('is_transcription_test', true);

            // Search by test ID - try exact match first, then partial match
            $query->where(function($q) use ($testId) {
                $q->where('job_id', $testId)
                  ->orWhere('job_id', 'like', "%{$testId}%")
                  ->orWhere('id', $testId);
            });

            $testResults = $query->orderBy('created_at', 'desc')->get();
            
            // Log the search attempt for debugging
            Log::info('Searching for transcription test results', [
                'test_id' => $testId,
                'query_count' => $testResults->count(),
                'found_job_ids' => $testResults->pluck('job_id')->toArray()
            ]);

            if ($testResults->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => "Transcription test result with ID {$testId} not found.",
                    'status' => 'not_found'
                ], 404);
            }

            // Get the most recent test result for progress tracking
            $latestTest = $testResults->first();

            // Calculate progress percentage based on status and timing
            $progressPercentage = 0;
            $statusMessage = 'Initializing...';

            switch ($latestTest->status) {
                case 'queued':
                    $progressPercentage = 5;
                    $statusMessage = 'Test queued for processing...';
                    break;
                case 'processing':
                    // Calculate progress based on timing if available
                    if ($latestTest->transcription_started_at) {
                        $startTime = $latestTest->transcription_started_at;
                        $now = now();
                        $elapsedSeconds = $now->diffInSeconds($startTime);
                        
                        // Estimate progress based on preset and elapsed time
                        $estimatedDuration = [
                            'fast' => 30,
                            'balanced' => 60,
                            'high' => 120,
                            'premium' => 300
                        ];
                        
                        $expectedDuration = $estimatedDuration[$latestTest->test_transcription_preset] ?? 60;
                        $calculatedProgress = min(95, 10 + (($elapsedSeconds / $expectedDuration) * 85));
                        $progressPercentage = max(10, $calculatedProgress);
                        
                        $statusMessage = "Processing {$latestTest->test_transcription_preset} transcription... ({$elapsedSeconds}s elapsed)";
                    } else {
                        $progressPercentage = 10;
                        $statusMessage = 'Starting transcription...';
                    }
                    break;
                case 'completed':
                    $progressPercentage = 100;
                    $statusMessage = 'Transcription completed successfully!';
                    break;
                case 'failed':
                    $progressPercentage = 0;
                    $statusMessage = $latestTest->error_message ?: 'Transcription failed';
                    break;
                default:
                    $progressPercentage = 0;
                    $statusMessage = 'Unknown status';
            }

            // Format results with enhanced progress information
            $formattedResults = $testResults->map(function ($log) {
                return [
                    'test_id' => $log->id,
                    'status' => $log->status,
                    'preset' => $log->test_transcription_preset,
                    'transcription_settings' => $log->transcription_settings,
                    'transcription_result' => $log->transcription_result,
                    'file_info' => [
                        'original_file' => $log->file_name,
                        'file_size' => $log->file_size,
                        'audio_duration_seconds' => $log->audio_duration_seconds
                    ],
                    'processing_time' => $log->total_processing_duration_seconds,
                    'transcription_duration' => $log->transcription_duration_seconds,
                    'error_message' => $log->error_message,
                    'started_at' => $log->started_at,
                    'transcription_started_at' => $log->transcription_started_at,
                    'transcription_completed_at' => $log->transcription_completed_at,
                    'completed_at' => $log->completed_at,
                    'created_at' => $log->created_at,
                    'updated_at' => $log->updated_at
                ];
            });

            $response = [
                'success' => true,
                'test_id' => $testId,
                'test_count' => $testResults->count(),
                'status' => $latestTest->status,
                'progress_percentage' => round($progressPercentage, 1),
                'status_message' => $statusMessage,
                'preset' => $latestTest->test_transcription_preset,
                'results' => $formattedResults->first(), // Return latest result for progress tracking
                'all_results' => $formattedResults // Include all results
            ];

            // Add timing information for active tests
            if (in_array($latestTest->status, ['queued', 'processing'])) {
                $response['timing'] = [
                    'queued_at' => $latestTest->created_at,
                    'started_at' => $latestTest->started_at,
                    'transcription_started_at' => $latestTest->transcription_started_at,
                    'elapsed_seconds' => $latestTest->started_at ? now()->diffInSeconds($latestTest->started_at) : 0
                ];
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('Error getting transcription test results', [
                'test_id' => $testId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving transcription test results',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'status' => 'error',
                'progress_percentage' => 0,
                'status_message' => 'Failed to retrieve test status'
            ], 500);
        }
    }
}