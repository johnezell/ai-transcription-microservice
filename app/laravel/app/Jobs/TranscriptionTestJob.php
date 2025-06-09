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

class TranscriptionTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The WAV file path for testing.
     *
     * @var string
     */
    protected $wavFilePath;

    /**
     * The WAV filename.
     *
     * @var string
     */
    protected $wavFilename;

    /**
     * The transcription preset for testing.
     *
     * @var string
     */
    protected $preset;

    /**
     * Additional transcription settings.
     *
     * @var array
     */
    protected $transcriptionSettings;

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
     * The test ID for tracking.
     *
     * @var string|null
     */
    protected $testId;

    /**
     * Create a new job instance.
     *
     * @param  string  $wavFilePath
     * @param  string  $wavFilename
     * @param  string  $preset
     * @param  array  $transcriptionSettings
     * @param  int|null  $segmentId
     * @param  int|null  $courseId
     * @param  string|null  $testId
     * @return void
     */
    public function __construct(
        string $wavFilePath,
        string $wavFilename,
        string $preset = 'balanced',
        array $transcriptionSettings = [],
        ?int $segmentId = null,
        ?int $courseId = null,
        ?string $testId = null
    ) {
        $this->wavFilePath = $wavFilePath;
        $this->wavFilename = $wavFilename;
        $this->preset = $preset;
        $this->transcriptionSettings = $transcriptionSettings;
        $this->segmentId = $segmentId;
        $this->courseId = $courseId;
        $this->testId = $testId;
        // Use default queue for all transcription test jobs
        // Removed custom queue specification to simplify queue management
        
        
        Log::info('TranscriptionTestJob created', [
            'wav_file_path' => $this->wavFilePath,
            'wav_filename' => $this->wavFilename,
            'preset' => $this->preset,
            'segment_id' => $this->segmentId,
            'course_id' => $this->courseId,
            'transcription_settings' => $this->transcriptionSettings,
            'test_id' => $this->testId,
            'workflow_step' => 'job_creation'
        ]);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('TranscriptionTestJob started processing', [
            'wav_file_path' => $this->wavFilePath,
            'wav_filename' => $this->wavFilename,
            'preset' => $this->preset,
            'segment_id' => $this->segmentId,
            'course_id' => $this->courseId,
            'test_id' => $this->testId,
            'workflow_step' => 'job_processing_start'
        ]);

        try {
            // For TrueFire courses, always use d_drive disk
            $disk = 'd_drive';
            
            // Make sure the WAV file exists
            if (!Storage::disk($disk)->exists($this->wavFilePath)) {
                Log::error('WAV file not found for transcription test job', [
                    'wav_file_path' => $this->wavFilePath,
                    'wav_filename' => $this->wavFilename,
                    'disk' => $disk,
                    'preset' => $this->preset,
                    'segment_id' => $this->segmentId,
                    'course_id' => $this->courseId,
                    'test_id' => $this->testId,
                    'full_path_attempted' => Storage::disk($disk)->path($this->wavFilePath),
                    'workflow_step' => 'file_not_found_error'
                ]);
                
                $this->updateTranscriptionLogWithError('WAV file not found for transcription testing');
                return;
            }
            
            Log::info('WAV file found, proceeding with transcription test', [
                'wav_file_path' => $this->wavFilePath,
                'disk' => $disk,
                'file_size' => Storage::disk($disk)->size($this->wavFilePath),
                'workflow_step' => 'file_validation_success'
            ]);
            
            // Use provided test ID or generate one
            $jobId = $this->testId ?? 'transcription_test_' . ($this->courseId ?? 'unknown') . '_' . ($this->segmentId ?? 'unknown') . '_' . time() . '_' . uniqid();
            
            // Create transcription log for tracking
            $log = TranscriptionLog::create([
                'job_id' => $jobId,
                'file_name' => $this->wavFilename,
                'file_path' => $this->wavFilePath,
                'file_size' => Storage::disk($disk)->size($this->wavFilePath),
                'status' => 'processing',
                'started_at' => now(),
                'is_transcription_test' => true,
                'test_transcription_preset' => $this->preset,
                'transcription_settings' => array_merge($this->transcriptionSettings, [
                    'segment_id' => $this->segmentId,
                    'course_id' => $this->courseId,
                    'test_mode' => true,
                    'initiated_at' => now()->toISOString()
                ])
            ]);
            
            Log::info('TranscriptionLog created for transcription test', [
                'transcription_log_id' => $log->id,
                'job_id' => $jobId,
                'workflow_step' => 'transcription_log_created'
            ]);
            
            // Update transcription start time
            $transcriptionStartTime = now();
            $log->update([
                'transcription_started_at' => $transcriptionStartTime,
                'status' => 'processing'
            ]);

            // Get the transcription service URL from environment
            $transcriptionServiceUrl = env('TRANSCRIPTION_SERVICE_URL', 'http://transcription-service:5000');
            
            // Log the test request
            Log::info('Dispatching transcription TEST request to Python service', [
                'wav_file_path' => $this->wavFilePath,
                'wav_filename' => $this->wavFilename,
                'service_url' => $transcriptionServiceUrl,
                'preset' => $this->preset,
                'transcription_settings' => $this->transcriptionSettings,
                'segment_id' => $this->segmentId,
                'course_id' => $this->courseId,
                'test_mode' => true,
                'workflow_step' => 'python_service_request_start'
            ]);

            // Send request to the transcription service with test parameters
            // For d_drive disk, we need to provide the full system path
            $fullAudioPath = Storage::disk($disk)->path($this->wavFilePath);
            
            $requestPayload = [
                'job_id' => $jobId,
                'audio_path' => $fullAudioPath,
                'preset' => $this->preset,
                'test_mode' => true,
                'transcription_settings' => array_merge($this->transcriptionSettings, [
                    'timestamp' => now()->timestamp,
                    'unique_id' => uniqid(),
                    'test_mode' => true
                ]),
                'segment_id' => $this->segmentId,
                'course_id' => $this->courseId
            ];

            Log::info('Sending HTTP request to Python transcription service', [
                'url' => "{$transcriptionServiceUrl}/transcribe",
                'payload' => $requestPayload,
                'timeout' => 300,
                'workflow_step' => 'python_service_http_request'
            ]);

            $response = Http::timeout(300)->post("{$transcriptionServiceUrl}/transcribe", $requestPayload);

            if ($response->successful()) {
                $responseData = $response->json();
                
                Log::info('Successfully dispatched transcription TEST request to Python service', [
                    'wav_file_path' => $this->wavFilePath,
                    'segment_id' => $this->segmentId,
                    'course_id' => $this->courseId,
                    'preset' => $this->preset,
                    'response_status' => $response->status(),
                    'response_body' => $responseData,
                    'workflow_step' => 'python_service_success'
                ]);
                
                // Update log with successful completion
                $transcriptionEndTime = now();
                $transcriptionDuration = $transcriptionEndTime->diffInSeconds($transcriptionStartTime);
                
                $log->update([
                    'status' => 'completed',
                    'transcription_result' => $responseData,
                    'transcription_completed_at' => $transcriptionEndTime,
                    'transcription_duration_seconds' => $transcriptionDuration,
                    'completed_at' => $transcriptionEndTime,
                    'total_processing_duration_seconds' => $transcriptionDuration,
                    'progress_percentage' => 100,
                    'response_data' => $responseData
                ]);
                
                Log::info('Transcription test completed successfully', [
                    'transcription_log_id' => $log->id,
                    'job_id' => $jobId,
                    'duration_seconds' => $transcriptionDuration,
                    'workflow_step' => 'test_completed_success'
                ]);
                
            } else {
                $errorMessage = 'Transcription test service returned error: ' . $response->body();
                Log::error('Python transcription service returned error', [
                    'wav_file_path' => $this->wavFilePath,
                    'segment_id' => $this->segmentId,
                    'course_id' => $this->courseId,
                    'preset' => $this->preset,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                    'error_message' => $errorMessage,
                    'workflow_step' => 'python_service_error'
                ]);
                
                // Update log with failure
                $this->updateTranscriptionLogWithError($errorMessage);
                
                $transcriptionEndTime = now();
                $transcriptionDuration = $transcriptionEndTime->diffInSeconds($transcriptionStartTime);
                
                $log->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                    'transcription_completed_at' => $transcriptionEndTime,
                    'transcription_duration_seconds' => $transcriptionDuration,
                    'completed_at' => $transcriptionEndTime,
                    'total_processing_duration_seconds' => $transcriptionDuration,
                    'progress_percentage' => 0
                ]);
            }
        } catch (\Exception $e) {
            $errorMessage = 'Exception in transcription test job: ' . $e->getMessage();
            Log::error('Critical exception in TranscriptionTestJob', [
                'wav_file_path' => $this->wavFilePath,
                'wav_filename' => $this->wavFilename,
                'segment_id' => $this->segmentId,
                'course_id' => $this->courseId,
                'preset' => $this->preset,
                'test_id' => $this->testId,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
                'workflow_step' => 'critical_exception'
            ]);
            
            // Update log with failure
            $this->updateTranscriptionLogWithError($errorMessage, $e);
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
            $log = TranscriptionLog::where('file_path', $this->wavFilePath)
                ->where('is_transcription_test', true)
                ->latest()
                ->first();
                
            if ($log) {
                $endTime = now();
                $startTime = $log->transcription_started_at ?? $log->started_at ?? $endTime;
                $duration = $endTime->diffInSeconds($startTime);
                
                $log->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                    'transcription_completed_at' => $endTime,
                    'transcription_duration_seconds' => $duration,
                    'completed_at' => $endTime,
                    'total_processing_duration_seconds' => $duration,
                    'progress_percentage' => 0
                ]);
                
                Log::info('TranscriptionLog updated with error information', [
                    'transcription_log_id' => $log->id,
                    'workflow_step' => 'error_log_updated'
                ]);
            }
        } catch (\Exception $logEx) {
            Log::error('Failed to update transcription log with error', [
                'wav_file_path' => $this->wavFilePath,
                'original_error' => $errorMessage,
                'log_update_error' => $logEx->getMessage(),
                'workflow_step' => 'error_log_update_failed'
            ]);
        }
    }

    /**
     * Get the WAV file path for this test job.
     *
     * @return string
     */
    public function getWavFilePath(): string
    {
        return $this->wavFilePath;
    }

    /**
     * Get the WAV filename for this test job.
     *
     * @return string
     */
    public function getWavFilename(): string
    {
        return $this->wavFilename;
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
     * Get the preset for this test job.
     *
     * @return string
     */
    public function getPreset(): string
    {
        return $this->preset;
    }

    /**
     * Get the transcription settings for this job.
     *
     * @return array
     */
    public function getTranscriptionSettings(): array
    {
        return $this->transcriptionSettings;
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
     * Get the test ID for this job.
     *
     * @return string|null
     */
    public function getTestId(): ?string
    {
        return $this->testId;
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
     * Get the tags that should be assigned to the job.
     *
     * @return array
     */
    public function tags(): array
    {
        $tags = [
            'transcription-test',
            'file:' . basename($this->wavFilePath),
            'preset:' . $this->preset
        ];

        if ($this->segmentId) {
            $tags[] = 'segment:' . $this->segmentId;
        }

        if ($this->courseId) {
            $tags[] = 'course:' . $this->courseId;
        }

        if ($this->testId) {
            $tags[] = 'test:' . $this->testId;
        }

        return $tags;
    }
}