<?php

namespace App\Jobs;

use App\Models\TruefireSegmentProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TruefireSegmentTranscriptionJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public $timeout = 3600; // 60 minutes for transcription
    public $tries = 3;

    protected $processing;
    protected $transcriptionPreset;

    /**
     * Create a new job instance.
     */
    public function __construct(TruefireSegmentProcessing $processing, string $transcriptionPreset = 'balanced')
    {
        $this->processing = $processing;
        $this->transcriptionPreset = $transcriptionPreset;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting TrueFire segment transcription', [
                'segment_id' => $this->processing->segment_id,
                'course_id' => $this->processing->course_id,
                'transcription_preset' => $this->transcriptionPreset,
                'job_id' => $this->job->getJobId()
            ]);

            // Check if audio file exists (handle both storage and D drive paths)
            $audioExists = false;
            if (!empty($this->processing->audio_path)) {
                if (str_contains($this->processing->audio_path, '/mnt/d_drive/')) {
                    // For D drive files, use file_exists
                    $audioExists = file_exists($this->processing->audio_path);
                } else {
                    // For storage files, use Storage facade
                    $audioExists = Storage::exists($this->processing->audio_path);
                }
            }
            
            if (!$audioExists) {
                throw new \Exception('Audio file not found for transcription: ' . $this->processing->audio_path);
            }

            // Get transcription service URL from environment (same as existing jobs)
            $transcriptionServiceUrl = env('TRANSCRIPTION_SERVICE_URL', 'http://transcription-service:5000');

            // Get audio file URL (handle both storage and D drive paths)
            if (str_contains($this->processing->audio_path, '/mnt/d_drive/')) {
                // For D drive files, the transcription service expects the actual file path
                $audioUrl = $this->processing->audio_path;
            } else {
                // For storage files, use Storage URL
                $audioUrl = Storage::url($this->processing->audio_path);
            }

            // The transcription service expects 'preset' parameter and will load preset configuration internally
            $requestData = [
                'job_id' => "truefire_segment_{$this->processing->segment_id}",
                'audio_path' => $this->processing->audio_path,
                'preset' => $this->transcriptionPreset,  // Send preset name, not transcription_preset
                'segment_id' => $this->processing->segment_id,
                'course_id' => $this->processing->course_id,
                'enable_intelligent_selection' => true  // Enable intelligent model selection
            ];

            Log::info('Sending TrueFire segment transcription request', [
                'url' => $transcriptionServiceUrl . '/process',
                'segment_id' => $this->processing->segment_id,
                'preset' => $this->transcriptionPreset,
                'audio_path' => $this->processing->audio_path
            ]);

            // Send request to transcription service (same endpoint as existing TranscriptionJob)
            $response = Http::timeout(180)->post($transcriptionServiceUrl . '/process', $requestData);

            if (!$response->successful()) {
                throw new \Exception('Transcription service request failed: ' . $response->body());
            }

            $responseData = $response->json();

            if (!isset($responseData['job_id'])) {
                throw new \Exception('Transcription service did not return job_id');
            }

            // Update processing record (don't override transcription_started_at - it's already set by startTranscription())
            $this->processing->update([
                'status' => 'transcribing',
                'progress_percentage' => 60
            ]);

            Log::info('TrueFire segment transcription request sent successfully', [
                'segment_id' => $this->processing->segment_id,
                'service_job_id' => $responseData['job_id']
            ]);

        } catch (\Exception $e) {
            Log::error('TrueFire segment transcription failed', [
                'segment_id' => $this->processing->segment_id,
                'course_id' => $this->processing->course_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Mark as failed
            $this->processing->markAsFailed('Transcription failed: ' . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TrueFire segment transcription job failed permanently', [
            'segment_id' => $this->processing->segment_id,
            'course_id' => $this->processing->course_id,
            'error' => $exception->getMessage()
        ]);

        // Mark processing as failed
        $this->processing->markAsFailed('Transcription job failed: ' . $exception->getMessage());
    }
}
