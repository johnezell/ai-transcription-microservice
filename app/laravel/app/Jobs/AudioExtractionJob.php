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
use Illuminate\Support\Facades\App;
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
    public function handle(): void
    {
        /* if (App::environment('local')) {
            Log::info('[AudioExtractionJob LOCAL] Simulating audio extraction success.', ['video_id' => $this->video->id]);
            $dummyAudioS3Key = 's3/jobs/' . $this->video->id . '/mock_local_audio.wav';
            
            // Simulate S3 upload if needed for consistency
            if (!Storage::disk('s3')->exists($dummyAudioS3Key)) { 
                Storage::disk('s3')->put($dummyAudioS3Key, 'This is a dummy audio file for local dev environment.', ['ACL' => 'bucket-owner-full-control']);
            }

            $this->video->update([
                'status' => 'audio_extracted',
                'audio_path' => $dummyAudioS3Key,
                'audio_duration' => rand(60, 300) + (rand(0, 99) / 100),
                'audio_size' => rand(1000000, 5000000),
            ]);

            $log = TranscriptionLog::firstOrCreate(
                ['video_id' => $this->video->id],
                ['job_id' => $this->video->id, 'started_at' => now()]
            );
            $log->update([
                'status' => 'audio_extracted',
                'audio_extraction_started_at' => $log->audio_extraction_started_at ?? now()->subSecond(),
                'audio_extraction_completed_at' => now(),
                'audio_file_size' => $this->video->audio_size,
                'audio_duration_seconds' => $this->video->audio_duration,
                'progress_percentage' => 50,
            ]);

            Log::info('[AudioExtractionJob LOCAL] Dispatching TranscriptionJob locally.', ['video_id' => $this->video->id]);
            TranscriptionJob::dispatch($this->video); // Dispatch next mocked job
            return; 
        } */

        // Original production logic starts here
        if (empty($this->video->storage_path) || !Storage::disk('s3')->exists($this->video->storage_path)) {
            Log::error('Video file not found on S3 for audio extraction job', [
                'video_id' => $this->video->id,
                'storage_path' => $this->video->storage_path
            ]);
            $this->video->update([
                'status' => 'failed',
                'error_message' => 'Video file not found for processing'
            ]);
            return;
        }

        $this->video->update(['status' => 'processing']);
        
        $log = TranscriptionLog::firstOrCreate(
            ['video_id' => $this->video->id],
            ['job_id' => $this->video->id, 'status' => 'processing', 'started_at' => now()]
        );
        $log->update(['audio_extraction_started_at' => now(), 'status' => 'processing']);

        $audioServiceUrl = env('AUDIO_SERVICE_URL');
        $payload = [
            'job_id' => (string) $this->video->id,
            'video_s3_key' => $this->video->storage_path,
            'app_data_bucket' => env('AWS_BUCKET') 
        ];

        Log::info('Dispatching audio extraction request to service', [
            'video_id' => $this->video->id,
            'service_url' => $audioServiceUrl,
            'payload' => $payload
        ]);

        try {
            $response = Http::timeout(300)->post($audioServiceUrl . '/process', $payload);
            if ($response->successful()) {
                Log::info('Successfully dispatched audio extraction request', ['video_id' => $this->video->id, 'response' => $response->json()]);
            } else {
                $errorMessage = 'Audio extraction service returned an error.';
                try { $errorMessage .= ' Status: ' . $response->status() . ' Body: ' . $response->body(); } catch (\Exception $_) {}
                Log::error($errorMessage, ['video_id' => $this->video->id]);
                $this->failJob($errorMessage);
            }
        } catch (\Exception $e) {
            Log::error('Exception calling Audio Extraction Service.', ['video_id' => $this->video->id, 'error' => $e->getMessage()]);
            $this->failJob('Exception calling Audio Extraction Service: ' . $e->getMessage());
        }
    }

    protected function failJob(string $errorMessage): void
    {
        $this->video->update(['status' => 'failed', 'error_message' => $errorMessage]);
        $log = TranscriptionLog::where('video_id', $this->video->id)->first();
        if ($log) {
            $log->update(['status' => 'failed', 'error_message' => $errorMessage, 'completed_at' => now()]);
        }
    }
} 