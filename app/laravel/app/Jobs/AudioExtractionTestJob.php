<?php

namespace App\Jobs;

use App\Models\Video;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The video model instance.
     *
     * @var \App\Models\Video
     */
    protected $video;

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
     * Create a new job instance.
     *
     * @param  \App\Models\Video  $video
     * @param  string  $qualityLevel
     * @param  array  $testSettings
     * @param  int|null  $segmentId
     * @return void
     */
    public function __construct(Video $video, string $qualityLevel = 'balanced', array $testSettings = [], ?int $segmentId = null)
    {
        $this->video = $video;
        $this->qualityLevel = $qualityLevel;
        $this->testSettings = $testSettings;
        $this->segmentId = $segmentId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            // Make sure the video file exists
            if (!Storage::disk('public')->exists($this->video->storage_path)) {
                Log::error('Video file not found for audio extraction test job', [
                    'video_id' => $this->video->id,
                    'storage_path' => $this->video->storage_path,
                    'quality_level' => $this->qualityLevel,
                    'segment_id' => $this->segmentId
                ]);
                
                $this->video->update([
                    'status' => 'failed',
                    'error_message' => 'Video file not found for testing'
                ]);
                
                return;
            }
            
            // Update status to processing
            $this->video->update([
                'status' => 'processing'
            ]);
            
            // Get or create transcription log with test-specific data
            $log = TranscriptionLog::firstOrCreate(
                ['video_id' => $this->video->id],
                [
                    'job_id' => $this->video->id,
                    'status' => 'processing',
                    'started_at' => now(),
                    'is_test_extraction' => true,
                    'test_quality_level' => $this->qualityLevel,
                    'extraction_settings' => array_merge($this->testSettings, [
                        'segment_id' => $this->segmentId,
                        'test_mode' => true,
                        'initiated_at' => now()->toISOString()
                    ])
                ]
            );
            
            // Update audio extraction start time and test settings
            $extractionStartTime = now();
            $log->update([
                'audio_extraction_started_at' => $extractionStartTime,
                'status' => 'processing',
                'is_test_extraction' => true,
                'test_quality_level' => $this->qualityLevel,
                'extraction_settings' => array_merge($this->testSettings, [
                    'segment_id' => $this->segmentId,
                    'test_mode' => true,
                    'initiated_at' => now()->toISOString()
                ])
            ]);

            // Get the audio service URL from environment
            $audioServiceUrl = env('AUDIO_SERVICE_URL', 'http://audio-extraction-service:5000');
            
            // Log the test request
            Log::info('Dispatching audio extraction TEST request to service', [
                'video_id' => $this->video->id,
                'service_url' => $audioServiceUrl,
                'storage_path' => $this->video->storage_path,
                'quality_level' => $this->qualityLevel,
                'test_settings' => $this->testSettings,
                'segment_id' => $this->segmentId,
                'test_mode' => true
            ]);

            // Send request to the audio extraction service with test parameters
            $requestPayload = [
                'job_id' => (string) $this->video->id,
                'video_path' => $this->video->storage_path,
                'quality_level' => $this->qualityLevel,
                'test_mode' => true,
                'test_settings' => $this->testSettings
            ];

            if ($this->segmentId) {
                $requestPayload['segment_id'] = $this->segmentId;
            }

            $response = Http::timeout(180)->post("{$audioServiceUrl}/process", $requestPayload);

            if ($response->successful()) {
                Log::info('Successfully dispatched audio extraction TEST request', [
                    'video_id' => $this->video->id,
                    'quality_level' => $this->qualityLevel,
                    'segment_id' => $this->segmentId,
                    'response' => $response->json()
                ]);
                
                // Don't complete here, the audio extraction service will call back
            } else {
                $errorMessage = 'Audio extraction test service returned error: ' . $response->body();
                Log::error($errorMessage, [
                    'video_id' => $this->video->id,
                    'quality_level' => $this->qualityLevel,
                    'segment_id' => $this->segmentId
                ]);
                
                // Update video and log with failure
                $this->video->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage
                ]);
                
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
            }
        } catch (\Exception $e) {
            $errorMessage = 'Exception in audio extraction test job: ' . $e->getMessage();
            Log::error($errorMessage, [
                'video_id' => $this->video->id,
                'quality_level' => $this->qualityLevel,
                'segment_id' => $this->segmentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Update video with failure
            $this->video->update([
                'status' => 'failed',
                'error_message' => $errorMessage
            ]);
            
            // Try to update log with timing information
            try {
                $log = TranscriptionLog::where('video_id', $this->video->id)->first();
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
                        'is_test_extraction' => true,
                        'test_quality_level' => $this->qualityLevel,
                        'audio_quality_metrics' => [
                            'test_failed' => true,
                            'error_type' => 'exception',
                            'quality_level' => $this->qualityLevel,
                            'exception_message' => $e->getMessage()
                        ]
                    ]);
                }
            } catch (\Exception $logEx) {
                Log::error('Failed to update transcription log for test job', [
                    'video_id' => $this->video->id,
                    'quality_level' => $this->qualityLevel,
                    'error' => $logEx->getMessage()
                ]);
            }
        }
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
}
