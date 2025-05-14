<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The video model instance.
     *
     * @var \App\Models\Video
     */
    protected Video $video;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\Video  $video
     * @return void
     */
    public function __construct(Video $video)
    {
        $this->video = $video;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        if (empty($this->video->audio_path)) {
            Log::error('[TranscriptionJob] Audio path is empty, cannot start transcription.', ['video_id' => $this->video->id]);
            $this->video->update(['status' => 'failed', 'error_message' => 'Audio path missing for transcription.']);
            return;
        }

        // Update video status to 'transcribing'
        $this->video->update(['status' => 'transcribing']);
        
        $transcriptionLog = \App\Models\TranscriptionLog::firstOrCreate(
            ['video_id' => $this->video->id],
            ['job_id' => $this->video->id, 'started_at' => now()]
        );
        $transcriptionLog->update([
            'status' => 'transcribing',
            'transcription_started_at' => now(),
            'progress_percentage' => 60 // Example progress: Transcription process initiated
        ]);

        $transcriptionServiceUrl = env('TRANSCRIPTION_SERVICE_URL', 'http://transcription-service.local:5000');
        $payload = [
            'job_id' => (string) $this->video->id,
            'audio_s3_key' => $this->video->audio_path, // Pass the S3 key of the audio file
            'model_name' => 'base' // Or make this configurable, e.g., via $this->video->model_preference
        ];

        Log::info('[TranscriptionJob] Dispatching request to Transcription Service.', [
            'video_id' => $this->video->id,
            'service_url' => $transcriptionServiceUrl,
            'payload' => $payload
        ]);

        try {
            $response = Http::timeout(3600) // Increased timeout for potentially long transcription
                            ->post("{$transcriptionServiceUrl}/process", $payload);

            if ($response->successful()) {
                Log::info('[TranscriptionJob] Successfully dispatched request to Transcription Service.', [
                    'video_id' => $this->video->id,
                    'response_status' => $response->status(),
                    // 'response_body' => $response->json() // Service will call back with full data
                ]);
                // Status will be updated by callback from transcription service
            } else {
                $errorMessage = 'Transcription service returned an error.';
                try { $errorMessage .= ' Status: ' . $response->status() . ' Body: ' . $response->body(); } catch (\Exception $_) {}
                Log::error($errorMessage, ['video_id' => $this->video->id]);
                $this->failJob($errorMessage);
            }
        } catch (\Exception $e) {
            Log::error('[TranscriptionJob] Exception calling Transcription Service.', [
                'video_id' => $this->video->id,
                'error' => $e->getMessage()
            ]);
            $this->failJob('Exception calling Transcription Service: ' . $e->getMessage());
        }
    }

    protected function failJob(string $errorMessage): void
    {
        $this->video->update(['status' => 'failed', 'error_message' => $errorMessage]);
        $transcriptionLog = \App\Models\TranscriptionLog::where('video_id', $this->video->id)->first();
        if ($transcriptionLog) {
            $transcriptionLog->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
                'completed_at' => now()
                // Consider also updating transcription_completed_at if it was started
            ]);
        }
    }
} 