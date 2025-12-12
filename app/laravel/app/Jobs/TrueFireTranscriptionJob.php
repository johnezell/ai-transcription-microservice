<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TrueFire Transcription Job
 * 
 * This job is dispatched with TrueFire segment data (not a Video model).
 * The ECS worker creates/updates the Video record in RDS when processing.
 * 
 * This architecture allows local dev to dispatch jobs without needing
 * direct access to the RDS database.
 */
class TrueFireTranscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Don't use SerializesModels - we pass data directly
    
    public array $segmentData;

    /**
     * Create a new job instance.
     * 
     * @param array $segmentData TrueFire segment data
     */
    public function __construct(array $segmentData)
    {
        $this->segmentData = $segmentData;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $data = $this->segmentData;
        
        Log::info('Processing TrueFire transcription job', [
            'segment_id' => $data['truefire_segment_id'] ?? null,
            's3_key' => $data['s3_key'] ?? null,
        ]);

        try {
            // Create or update Video record in RDS
            $video = Video::updateOrCreate(
                ['s3_key' => $data['s3_key']],
                [
                    'original_filename' => $data['original_filename'],
                    'mime_type' => $data['mime_type'] ?? 'video/mp4',
                    'size_bytes' => $data['size_bytes'] ?? 0,
                    'status' => 'processing',
                    'metadata' => $data['metadata'] ?? [],
                ]
            );

            Log::info('Video record created/updated', [
                'video_id' => $video->id,
                'segment_id' => $data['metadata']['truefire_segment_id'] ?? null,
            ]);

            // Get or create transcription log
            $log = \App\Models\TranscriptionLog::firstOrCreate(
                ['video_id' => $video->id],
                [
                    'job_id' => $video->id,
                    'status' => 'processing',
                    'started_at' => now(),
                ]
            );

            // Update audio extraction start time
            $extractionStartTime = now();
            $log->update([
                'audio_extraction_started_at' => $extractionStartTime,
                'status' => 'processing',
            ]);

            // Get the audio service URL from environment
            $audioServiceUrl = env('AUDIO_SERVICE_URL', 'http://audio-extraction-service:5000');

            Log::info('Dispatching to audio extraction service', [
                'video_id' => $video->id,
                'service_url' => $audioServiceUrl,
                's3_bucket' => $data['metadata']['s3_bucket'] ?? 'tfstream',
                's3_key' => $data['s3_key'],
            ]);

            // Send request to the audio extraction service
            // Include S3 info so the service can download directly
            $response = Http::timeout(300)->post("{$audioServiceUrl}/process", [
                'job_id' => (string) $video->id,
                's3_bucket' => $data['metadata']['s3_bucket'] ?? 'tfstream',
                's3_key' => $data['s3_key'],
            ]);

            if ($response->successful()) {
                Log::info('Successfully dispatched audio extraction request', [
                    'video_id' => $video->id,
                    'response' => $response->json(),
                ]);
            } else {
                $this->handleFailure($video, $log, 'Audio extraction service error: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('TrueFire transcription job failed', [
                'segment_id' => $data['truefire_segment_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            // If we have a video record, mark it as failed
            if (isset($video)) {
                $this->handleFailure($video, $log ?? null, $e->getMessage());
            }

            throw $e; // Re-throw to mark job as failed
        }
    }

    private function handleFailure(Video $video, $log, string $errorMessage): void
    {
        $video->update([
            'status' => 'failed',
            'metadata' => array_merge($video->metadata ?? [], [
                'error_message' => $errorMessage,
                'failed_at' => now()->toIso8601String(),
            ]),
        ]);

        if ($log) {
            $log->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
                'completed_at' => now(),
            ]);
        }
    }
}



