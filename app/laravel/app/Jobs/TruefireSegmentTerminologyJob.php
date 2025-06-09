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

class TruefireSegmentTerminologyJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public $timeout = 1800; // 30 minutes for terminology recognition
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
            Log::info('Starting TrueFire segment terminology recognition', [
                'segment_id' => $this->processing->segment_id,
                'course_id' => $this->processing->course_id,
                'job_id' => $this->job->getJobId()
            ]);

            // Check if transcript file exists
            if (empty($this->processing->transcript_path) || !Storage::exists($this->processing->transcript_path)) {
                throw new \Exception('Transcript file not found for terminology recognition');
            }

            // Get terminology service URL from environment (same as existing jobs)
            $terminologyServiceUrl = env('MUSIC_TERM_SERVICE_URL', 'http://music-term-recognition-service:5000');

            // Get transcript file URL
            $transcriptUrl = Storage::url($this->processing->transcript_path);

            // Terminology recognition settings
            $settings = [
                'recognition_type' => 'music_guitar', // Default for TrueFire
                'confidence_threshold' => 0.7,
                'include_categories' => [
                    'guitar_parts',
                    'music_theory',
                    'techniques',
                    'equipment',
                    'effects'
                ],
                'output_format' => 'json'
            ];

            // The service will automatically callback to /api/transcription/{job_id}/status
            $requestData = [
                'job_id' => "truefire_segment_{$this->processing->segment_id}",
                'transcript_path' => $this->processing->transcript_path,
                'settings' => $settings
            ];

            Log::info('Sending TrueFire segment terminology recognition request', [
                'url' => $terminologyServiceUrl . '/process',
                'segment_id' => $this->processing->segment_id
            ]);

            // Send request to terminology service (same endpoint as existing TerminologyRecognitionJob)
            $response = Http::timeout(180)->post($terminologyServiceUrl . '/process', $requestData);

            if (!$response->successful()) {
                throw new \Exception('Terminology service request failed: ' . $response->body());
            }

            $responseData = $response->json();

            if (!isset($responseData['job_id'])) {
                throw new \Exception('Terminology service did not return job_id');
            }

            // Update processing record
            $this->processing->update([
                'status' => 'processing_terminology',
                'terminology_started_at' => now(),
                'progress_percentage' => 85
            ]);

            Log::info('TrueFire segment terminology recognition request sent successfully', [
                'segment_id' => $this->processing->segment_id,
                'service_job_id' => $responseData['job_id']
            ]);

        } catch (\Exception $e) {
            Log::error('TrueFire segment terminology recognition failed', [
                'segment_id' => $this->processing->segment_id,
                'course_id' => $this->processing->course_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Mark as failed
            $this->processing->markAsFailed('Terminology recognition failed: ' . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TrueFire segment terminology recognition job failed permanently', [
            'segment_id' => $this->processing->segment_id,
            'course_id' => $this->processing->course_id,
            'error' => $exception->getMessage()
        ]);

        // Mark processing as failed
        $this->processing->markAsFailed('Terminology recognition job failed: ' . $exception->getMessage());
    }
}
