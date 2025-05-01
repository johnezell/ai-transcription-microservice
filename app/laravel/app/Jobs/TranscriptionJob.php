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
    protected $video;

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
    public function handle()
    {
        try {
            // Update video status to transcribing
            $this->video->update(['status' => 'transcribing']);
            
            // Get the transcription service URL from environment
            $transcriptionServiceUrl = env('TRANSCRIPTION_SERVICE_URL', 'http://transcription-service:5000');
            
            // Log the request
            Log::info('Dispatching transcription request to service', [
                'video_id' => $this->video->id,
                'service_url' => $transcriptionServiceUrl
            ]);

            // Send request to the transcription service
            $response = Http::timeout(180)->post("{$transcriptionServiceUrl}/process", [
                'job_id' => (string) $this->video->id
            ]);

            if ($response->successful()) {
                Log::info('Successfully dispatched transcription request', [
                    'video_id' => $this->video->id,
                    'response' => $response->json()
                ]);
            } else {
                $errorMessage = 'Transcription service returned error: ' . $response->body();
                Log::error($errorMessage, [
                    'video_id' => $this->video->id
                ]);
                
                $this->video->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage
                ]);
            }
        } catch (\Exception $e) {
            $errorMessage = 'Exception in transcription job: ' . $e->getMessage();
            Log::error($errorMessage, [
                'video_id' => $this->video->id,
                'error' => $e->getMessage()
            ]);
            
            $this->video->update([
                'status' => 'failed',
                'error_message' => $errorMessage
            ]);
        }
    }
} 