<?php

namespace App\Jobs;

use App\Models\TranscriptionLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AudioExtractionTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The video file path for testing.
     *
     * @var string
     */
    protected $videoFilePath;

    /**
     * The video filename.
     *
     * @var string
     */
    protected $videoFilename;

    /**
     * The quality level for testing.
     *
     * @var string
     */
    protected $qualityLevel;

    /**
     * Additional test settings.
     *
     * @var array
     */
    protected $testSettings;

    /**
     * Segment ID for TrueFire course testing.
     *
     * @var int|null
     */
    protected $segmentId;

    /**
     * Course ID for TrueFire testing.
     *
     * @var int|null
     */
    protected $courseId;

    /**
     * The batch context for this job.
     *
     * @var \App\Models\AudioTestBatch|null
     */
    protected $batchContext;

    /**
     * The transcription log for batch processing.
     *
     * @var \App\Models\TranscriptionLog|null
     */
    protected $batchTranscriptionLog;

    /**
     * Create a new job instance.
     *
     * @param  string  $videoFilePath
     * @param  string  $videoFilename
     * @param  string  $qualityLevel
     * @param  array  $testSettings
     * @param  int|null  $segmentId
     * @param  int|null  $courseId
     * @param  string|null  $jobId
     * @return void
     */
    public function __construct(string $videoFilePath, string $videoFilename, string $qualityLevel = 'balanced', array $testSettings = [], ?int $segmentId = null, ?int $courseId = null, ?string $jobId = null)
    {
        $this->videoFilePath = $videoFilePath;
        $this->videoFilename = $videoFilename;
        $this->qualityLevel = $qualityLevel;
        $this->testSettings = $testSettings;
        $this->segmentId = $segmentId;
        $this->courseId = $courseId;
        
        // Store the job ID passed from controller, or generate one if not provided
        if ($jobId) {
            $this->testSettings['controller_job_id'] = $jobId;
        }
        // Use default queue for all audio extraction test jobs
        // Removed custom queue specification to simplify queue management
        
        
        Log::info('AudioExtractionTestJob created', [
            'video_file_path' => $this->videoFilePath,
            'video_filename' => $this->videoFilename,
            'quality_level' => $this->qualityLevel,
            'segment_id' => $this->segmentId,
            'course_id' => $this->courseId,
            'test_settings' => $this->testSettings,
            'job_id' => $jobId,
            'workflow_step' => 'job_creation'
        ]);
    }

    /**
     * Set the batch context for this job.
     *
     * @param \App\Models\AudioTestBatch $batch
     * @param \App\Models\TranscriptionLog $transcriptionLog
     * @return void
     */
    public function setBatchContext(AudioTestBatch $batch, TranscriptionLog $transcriptionLog): void
    {
        $this->batchContext = $batch;
        $this->batchTranscriptionLog = $transcriptionLog;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('AudioExtractionTestJob started processing', [
            'video_file_path' => $this->videoFilePath,
            'video_filename' => $this->videoFilename,
            'quality_level' => $this->qualityLevel,
            'segment_id' => $this->segmentId,
            'course_id' => $this->courseId,
            'workflow_step' => 'job_processing_start'
        ]);

        try {
            // For TrueFire courses, always use d_drive disk
            $disk = 'd_drive';
            
            // Make sure the video file exists
            if (!Storage::disk($disk)->exists($this->videoFilePath)) {
                Log::error('Video file not found for audio extraction test job', [
                    'video_file_path' => $this->videoFilePath,
                    'video_filename' => $this->videoFilename,
                    'disk' => $disk,
                    'quality_level' => $this->qualityLevel,
                    'segment_id' => $this->segmentId,
                    'course_id' => $this->courseId,
                    'full_path_attempted' => Storage::disk($disk)->path($this->videoFilePath),
                    'workflow_step' => 'file_not_found_error'
                ]);
                
                $this->updateTranscriptionLogWithError('Video file not found for testing');
                return;
            }
            
            Log::info('Video file found, proceeding with audio extraction test', [
                'video_file_path' => $this->videoFilePath,
                'disk' => $disk,
                'file_size' => Storage::disk($disk)->size($this->videoFilePath),
                'workflow_step' => 'file_validation_success'
            ]);
            
            // Use job ID from controller if available, otherwise generate one
            if (isset($this->testSettings['controller_job_id'])) {
                $jobId = $this->testSettings['controller_job_id'];
                Log::info('Using job ID from controller', ['job_id' => $jobId]);
            } else {
                // Fallback: Create job ID that matches what the controller expects
                $timestamp = time();
                $uniqueId = uniqid();
                $jobId = 'audio_extract_test_' . ($this->courseId ?? 'unknown') . '_' . ($this->segmentId ?? 'unknown') . '_' . $timestamp . '_' . $this->qualityLevel . '_' . $uniqueId;
                Log::info('Generated fallback job ID', ['job_id' => $jobId]);
            }
            
            $log = $this->batchTranscriptionLog ?? TranscriptionLog::create([
                'job_id' => $jobId,
                'file_name' => $this->videoFilename,
                'file_path' => $this->videoFilePath,
                'file_size' => Storage::disk($disk)->size($this->videoFilePath),
                'status' => 'processing',
                'started_at' => now(),
                'is_test_extraction' => true,
                'test_quality_level' => $this->qualityLevel,
                'extraction_settings' => array_merge($this->testSettings, [
                    'segment_id' => $this->segmentId,
                    'course_id' => $this->courseId,
                    'test_mode' => true,
                    'initiated_at' => now()->toISOString()
                ])
            ]);
            
            Log::info('TranscriptionLog created for audio extraction test', [
                'transcription_log_id' => $log->id,
                'job_id' => $jobId,
                'workflow_step' => 'transcription_log_created'
            ]);
            
            // Update audio extraction start time and test settings
            $extractionStartTime = now();
            $log->update([
                'audio_extraction_started_at' => $extractionStartTime,
                'status' => 'processing'
            ]);

            // Get the audio service URL from environment
            $audioServiceUrl = env('AUDIO_SERVICE_URL', 'http://audio-extraction-service:5000');
            
            // Log the test request
            Log::info('Dispatching audio extraction TEST request to Python service', [
                'video_file_path' => $this->videoFilePath,
                'video_filename' => $this->videoFilename,
                'service_url' => $audioServiceUrl,
                'quality_level' => $this->qualityLevel,
                'test_settings' => $this->testSettings,
                'segment_id' => $this->segmentId,
                'course_id' => $this->courseId,
                'test_mode' => true,
                'workflow_step' => 'python_service_request_start'
            ]);

            // Determine if this is for transcription workflow or testing
            $forTranscription = $this->testSettings['for_transcription'] ?? false;
            $outputFormat = $forTranscription ? 'mp3' : 'wav';
            $simpleNaming = $forTranscription; // Use simple naming for transcription workflow

            // Check if quality analysis is enabled
            $enableQualityAnalysis = $this->testSettings['enable_quality_analysis'] ?? false;
            
            // Send request to the audio extraction service with test parameters
            $requestPayload = [
                'job_id' => $jobId,
                'video_path' => $this->videoFilePath,
                'quality_level' => $this->qualityLevel,
                'test_mode' => !$forTranscription, // Test mode is false for transcription workflow
                'for_transcription' => $forTranscription,
                'output_format' => $outputFormat,
                'simple_naming' => $simpleNaming,
                'enable_quality_analysis' => $enableQualityAnalysis,
                'test_settings' => array_merge($this->testSettings, [
                    'timestamp' => now()->timestamp,
                    'unique_id' => uniqid(),
                    'output_format' => $outputFormat,
                    'simple_naming' => $simpleNaming,
                    'enable_quality_analysis' => $enableQualityAnalysis
                ]),
                'segment_id' => $this->segmentId,
                'course_id' => $this->courseId
            ];

            Log::info('Sending HTTP request to Python audio extraction service', [
                'url' => "{$audioServiceUrl}/process",
                'payload' => $requestPayload,
                'timeout' => 180,
                'workflow_step' => 'python_service_http_request'
            ]);

            $response = Http::timeout(180)->post("{$audioServiceUrl}/process", $requestPayload);

            if ($response->successful()) {
                Log::info('Successfully dispatched audio extraction TEST request to Python service', [
                    'video_file_path' => $this->videoFilePath,
                    'segment_id' => $this->segmentId,
                    'course_id' => $this->courseId,
                    'quality_level' => $this->qualityLevel,
                    'response_status' => $response->status(),
                    'response_body' => $response->json(),
                    'workflow_step' => 'python_service_success'
                ]);
                
                // Don't complete here, the audio extraction service will call back
                
                // Update batch progress if this is part of a batch
                if ($this->batchContext) {
                    $this->updateBatchProgress();
                }
            } else {
                $errorMessage = 'Audio extraction test service returned error: ' . $response->body();
                Log::error('Python audio extraction service returned error', [
                    'video_file_path' => $this->videoFilePath,
                    'segment_id' => $this->segmentId,
                    'course_id' => $this->courseId,
                    'quality_level' => $this->qualityLevel,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                    'error_message' => $errorMessage,
                    'workflow_step' => 'python_service_error'
                ]);
                
                // Update log with failure
                $this->updateTranscriptionLogWithError($errorMessage);
                
                $extractionEndTime = now();
                $extractionDuration = $extractionEndTime->diffInSeconds($extractionStartTime);
                
                $log->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                    'audio_extraction_completed_at' => $extractionEndTime,
                    'audio_extraction_duration_seconds' => $extractionDuration,
                    'completed_at' => $extractionEndTime,
                    'total_processing_duration_seconds' => $extractionDuration,
                    'progress_percentage' => 0,
                    'audio_quality_metrics' => [
                        'test_failed' => true,
                        'error_type' => 'service_error',
                        'quality_level' => $this->qualityLevel
                    ]
                ]);
                
                // Update batch progress if this is part of a batch
                if ($this->batchContext) {
                    $this->updateBatchProgress();
                }
            }
        } catch (\Exception $e) {
            $errorMessage = 'Exception in audio extraction test job: ' . $e->getMessage();
            Log::error('Critical exception in AudioExtractionTestJob', [
                'video_file_path' => $this->videoFilePath,
                'video_filename' => $this->videoFilename,
                'segment_id' => $this->segmentId,
                'course_id' => $this->courseId,
                'quality_level' => $this->qualityLevel,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
                'workflow_step' => 'critical_exception'
            ]);
            
            // Update log with failure
            $this->updateTranscriptionLogWithError($errorMessage, $e);
            
            // Update batch progress if this is part of a batch
            if ($this->batchContext) {
                $this->updateBatchProgress();
            }
        }
    }

    /**
     * Update transcription log with error information.
     *
     * @param string $errorMessage
     * @param \Exception|null $exception
     * @return void
     */
    protected function updateTranscriptionLogWithError(string $errorMessage, ?\Exception $exception = null): void
    {
        try {
            $log = TranscriptionLog::where('file_path', $this->videoFilePath)
                ->where('is_test_extraction', true)
                ->latest()
                ->first();
                
            if ($log) {
                $endTime = now();
                $startTime = $log->audio_extraction_started_at ?? $log->started_at ?? $endTime;
                $duration = $endTime->diffInSeconds($startTime);
                
                $log->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                    'audio_extraction_completed_at' => $endTime,
                    'audio_extraction_duration_seconds' => $duration,
                    'completed_at' => $endTime,
                    'total_processing_duration_seconds' => $duration,
                    'progress_percentage' => 0,
                    'audio_quality_metrics' => [
                        'test_failed' => true,
                        'error_type' => $exception ? 'exception' : 'service_error',
                        'quality_level' => $this->qualityLevel,
                        'error_message' => $errorMessage,
                        'exception_class' => $exception ? get_class($exception) : null
                    ]
                ]);
                
                Log::info('TranscriptionLog updated with error information', [
                    'transcription_log_id' => $log->id,
                    'workflow_step' => 'error_log_updated'
                ]);
            }
        } catch (\Exception $logEx) {
            Log::error('Failed to update transcription log with error', [
                'video_file_path' => $this->videoFilePath,
                'original_error' => $errorMessage,
                'log_update_error' => $logEx->getMessage(),
                'workflow_step' => 'error_log_update_failed'
            ]);
        }
    }

    /**
     * Update batch progress counters.
     *
     * @return void
     */
    protected function updateBatchProgress(): void
    {
        if ($this->batchContext) {
            try {
                $this->batchContext->updateProgress();
            } catch (\Exception $e) {
                Log::warning('Failed to update batch progress', [
                    'batch_id' => $this->batchContext->id,
                    'video_file_path' => $this->videoFilePath,
                    'segment_id' => $this->segmentId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Get the video file path for this test job.
     *
     * @return string
     */
    public function getVideoFilePath(): string
    {
        return $this->videoFilePath;
    }

    /**
     * Get the video filename for this test job.
     *
     * @return string
     */
    public function getVideoFilename(): string
    {
        return $this->videoFilename;
    }

    /**
     * Get the course ID for this test job.
     *
     * @return int|null
     */
    public function getCourseId(): ?int
    {
        return $this->courseId;
    }

    /**
     * Get the quality level for this test job.
     *
     * @return string
     */
    public function getQualityLevel(): string
    {
        return $this->qualityLevel;
    }

    /**
     * Get the test settings for this job.
     *
     * @return array
     */
    public function getTestSettings(): array
    {
        return $this->testSettings;
    }

    /**
     * Get the segment ID if this is a TrueFire segment test.
     *
     * @return int|null
     */
    public function getSegmentId(): ?int
    {
        return $this->segmentId;
    }

    /**
     * Determine if this is a test job.
     *
     * @return bool
     */
    public function isTestJob(): bool
    {
        return true;
    }

    /**
     * Check if this job is part of a batch.
     *
     * @return bool
     */
    public function isBatchJob(): bool
    {
        return !is_null($this->batchContext);
    }

    /**
     * Get the batch context.
     *
     * @return \App\Models\AudioTestBatch|null
     */
    public function getBatchContext(): ?AudioTestBatch
    {
        return $this->batchContext;
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array
     */
    public function tags(): array
    {
        $tags = [
            'audio-extraction-test',
            'file:' . basename($this->videoFilePath),
            'quality:' . $this->qualityLevel
        ];

        if ($this->segmentId) {
            $tags[] = 'segment:' . $this->segmentId;
        }

        if ($this->courseId) {
            $tags[] = 'course:' . $this->courseId;
        }

        if ($this->batchContext) {
            $tags[] = 'batch:' . $this->batchContext->id;
            $tags[] = 'batch-item';
        }

        return $tags;
    }
}
