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
use Illuminate\Support\Facades\Storage;

class AudioExtractionJob implements ShouldQueue
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
            // Make sure the video file exists
            if (!Storage::disk('public')->exists($this->video->storage_path)) {
                Log::error('Video file not found for audio extraction job', [
                    'video_id' => $this->video->id,
                    'storage_path' => $this->video->storage_path
                ]);
                
                $this->video->update([
                    'status' => 'failed',
                    'error_message' => 'Video file not found for processing'
                ]);
                
                return;
            }
            
            // Update status to processing
            $this->video->update([
                'status' => 'processing'
            ]);

            // Get the audio service URL from environment
            $audioServiceUrl = env('AUDIO_SERVICE_URL', 'http://audio-extraction-service:5000');
            
            // Log the request
            Log::info('Dispatching audio extraction request to service', [
                'video_id' => $this->video->id,
                'service_url' => $audioServiceUrl,
                'storage_path' => $this->video->storage_path
            ]);

            // Send request to the audio extraction service
            $response = Http::timeout(180)->post("{$audioServiceUrl}/process", [
                'job_id' => (string) $this->video->id,
                'video_path' => $this->video->storage_path
            ]);

            if ($response->successful()) {
                Log::info('Successfully dispatched audio extraction request', [
                    'video_id' => $this->video->id,
                    'response' => $response->json()
                ]);
            } else {
                $errorMessage = 'Audio extraction service returned error: ' . $response->body();
                Log::error($errorMessage, [
                    'video_id' => $this->video->id
                ]);
                
                $this->video->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage
                ]);
            }
        } catch (\Exception $e) {
            $errorMessage = 'Exception in audio extraction job: ' . $e->getMessage();
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