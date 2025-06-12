<?php

namespace App\Jobs;

use App\Models\TruefireSegmentProcessing;
use App\Models\LocalTruefireSegment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TruefireSegmentAudioExtractionJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public $timeout = 1800; // 30 minutes
    public $tries = 3;

    protected $processing;

    /**
     * Create a new job instance.
     */
    public function __construct(TruefireSegmentProcessing $processing)
    {
        $this->processing = $processing;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting TrueFire segment audio extraction', [
                'segment_id' => $this->processing->segment_id,
                'course_id' => $this->processing->course_id,
                'job_id' => $this->job->getJobId()
            ]);

            // Get the segment
            $segment = LocalTruefireSegment::find($this->processing->segment_id);
            if (!$segment) {
                throw new \Exception('Segment not found');
            }

            // Check if the video file is downloaded locally (like regular videos)
            $courseDir = "truefire-courses/{$this->processing->course_id}";
            $videoFilename = "{$this->processing->segment_id}.mp4";
            $videoPath = "{$courseDir}/{$videoFilename}";
            
            // Use d_drive disk like TrueFire course downloads
            $disk = 'd_drive';
            
            if (!Storage::disk($disk)->exists($videoPath)) {
                throw new \Exception('Video file not found locally. Please download the segment first. Expected path: ' . $videoPath);
            }
            
            // For TrueFire videos, use the container mount path that both Laravel and audio service can access
            // Both containers have D:/ mounted at /mnt/d_drive, so use that path directly
            $containerVideoPath = "/mnt/d_drive/{$videoPath}";

            // Get the audio service URL from environment (same as existing AudioExtractionJob)
            $audioServiceUrl = env('AUDIO_SERVICE_URL', 'http://audio-extraction-service:5000');

            // Create job directory
            $jobDir = "truefire-segments/{$this->processing->course_id}/{$this->processing->segment_id}";
            Storage::makeDirectory($jobDir);

            // Use the same request structure as regular AudioExtractionJob (video_path not video_url)
            // The service will automatically callback to /api/transcription/{job_id}/status
            $requestData = [
                'job_id' => "truefire_segment_{$this->processing->segment_id}",
                'video_path' => $containerVideoPath,
                // Add TrueFire-specific parameters so the service knows to use the correct callback
                'segment_id' => $this->processing->segment_id,
                'course_id' => $this->processing->course_id,
                // Include extraction settings for automatic transcription triggering
                'extraction_settings' => [
                    'follow_with_transcription' => true,
                    'transcription_preset' => 'balanced',
                    'complete_pipeline_restart' => false,
                    'for_transcription' => true,
                    'output_format' => 'wav',
                    'sample_rate' => 16000,
                    'channels' => 1,
                    'bit_rate' => '128k'
                ]
            ];

            Log::info('Sending TrueFire segment audio extraction request', [
                'url' => $audioServiceUrl . '/process',
                'segment_id' => $this->processing->segment_id,
                'video_path' => $containerVideoPath,
                'relative_path' => $videoPath,
                'video_exists' => Storage::disk($disk)->exists($videoPath),
                'file_size' => Storage::disk($disk)->exists($videoPath) ? Storage::disk($disk)->size($videoPath) : 0
            ]);

            // Send request to audio extraction service (same endpoint as existing AudioExtractionJob)
            $response = Http::timeout(180)->post($audioServiceUrl . '/process', $requestData);

            if (!$response->successful()) {
                throw new \Exception('Audio extraction service request failed: ' . $response->body());
            }

            $responseData = $response->json();

            if (!isset($responseData['job_id'])) {
                throw new \Exception('Audio extraction service did not return job_id');
            }

            // Processing record already updated with start time when job was dispatched
            // Just log that we're starting the actual service call

            Log::info('TrueFire segment audio extraction request sent successfully', [
                'segment_id' => $this->processing->segment_id,
                'service_job_id' => $responseData['job_id']
            ]);

        } catch (\Exception $e) {
            Log::error('TrueFire segment audio extraction failed', [
                'segment_id' => $this->processing->segment_id,
                'course_id' => $this->processing->course_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Mark as failed
            $this->processing->markAsFailed('Audio extraction failed: ' . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TrueFire segment audio extraction job failed permanently', [
            'segment_id' => $this->processing->segment_id,
            'course_id' => $this->processing->course_id,
            'error' => $exception->getMessage()
        ]);

        // Mark processing as failed
        $this->processing->markAsFailed('Audio extraction job failed: ' . $exception->getMessage());
    }
}
