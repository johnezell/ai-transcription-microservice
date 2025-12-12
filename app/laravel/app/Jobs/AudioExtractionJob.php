<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Aws\S3\S3Client;
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
            // Determine video source: local storage or TrueFire S3
            $isS3Source = !empty($this->video->s3_key) && empty($this->video->storage_path);
            $metadata = $this->video->metadata ?? [];
            
            // Create job directory for processing files
            $jobDir = storage_path('app/public/s3/jobs/' . $this->video->id);
            if (!is_dir($jobDir)) {
                mkdir($jobDir, 0755, true);
            }
            $videoPath = $jobDir . '/video.mp4';
            
            if ($isS3Source) {
                // TrueFire S3 video - verify metadata and download
                if (empty($metadata['s3_bucket'])) {
                    Log::error('TrueFire video missing S3 bucket info', [
                        'video_id' => $this->video->id,
                        's3_key' => $this->video->s3_key,
                        'metadata' => $metadata
                    ]);
                    
                    $this->video->update([
                        'status' => 'failed',
                        'error_message' => 'S3 bucket information missing for TrueFire video'
                    ]);
                    
                    return;
                }
                
                Log::info('Downloading TrueFire S3 video', [
                    'video_id' => $this->video->id,
                    's3_bucket' => $metadata['s3_bucket'],
                    's3_key' => $this->video->s3_key,
                    'target_path' => $videoPath
                ]);
                
                // Download from TrueFire S3
                $this->downloadFromS3($metadata['s3_bucket'], $this->video->s3_key, $videoPath);
                
                if (!file_exists($videoPath)) {
                    $this->video->update([
                        'status' => 'failed',
                        'error_message' => 'Failed to download video from S3'
                    ]);
                    return;
                }
                
                Log::info('Successfully downloaded video from S3', [
                    'video_id' => $this->video->id,
                    'size' => filesize($videoPath)
                ]);
            } else {
                // Local storage video - verify file exists
                if (empty($this->video->storage_path) || !Storage::disk('public')->exists($this->video->storage_path)) {
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
            }
            
            // Update status to processing
            $this->video->update([
                'status' => 'processing'
            ]);
            
            // Get or create transcription log
            $log = \App\Models\TranscriptionLog::firstOrCreate(
                ['video_id' => $this->video->id],
                [
                    'job_id' => $this->video->id,
                    'status' => 'processing',
                    'started_at' => now(),
                ]
            );
            
            // Update audio extraction start time
            $extractionStartTime = now();
            $log->update([
                'audio_extraction_started_at' => $extractionStartTime,
                'status' => 'processing'
            ]);

            // Get the audio service URL from environment
            $audioServiceUrl = env('AUDIO_SERVICE_URL', 'http://audio-extraction-service:5000');
            
            // Build request payload - audio service expects video in job directory
            $payload = [
                'job_id' => (string) $this->video->id,
            ];
            
            // Log the request
            Log::info('Dispatching audio extraction request to service', [
                'video_id' => $this->video->id,
                'service_url' => $audioServiceUrl,
                'payload' => $payload
            ]);

            // Send request to the audio extraction service
            $response = Http::timeout(300)->post("{$audioServiceUrl}/process", $payload);

            if ($response->successful()) {
                Log::info('Successfully dispatched audio extraction request', [
                    'video_id' => $this->video->id,
                    'response' => $response->json()
                ]);
                
                // Don't complete here, the audio extraction service will call back
            } else {
                $errorMessage = 'Audio extraction service returned error: ' . $response->body();
                Log::error($errorMessage, [
                    'video_id' => $this->video->id
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
                    'progress_percentage' => 0
                ]);
            }
        } catch (\Exception $e) {
            $errorMessage = 'Exception in audio extraction job: ' . $e->getMessage();
            Log::error($errorMessage, [
                'video_id' => $this->video->id,
                'error' => $e->getMessage()
            ]);
            
            // Update video with failure
            $this->video->update([
                'status' => 'failed',
                'error_message' => $errorMessage
            ]);
            
            // Try to update log with timing information
            try {
                $log = \App\Models\TranscriptionLog::where('video_id', $this->video->id)->first();
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
                        'progress_percentage' => 0
                    ]);
                }
            } catch (\Exception $logEx) {
                Log::error('Failed to update transcription log', [
                    'video_id' => $this->video->id,
                    'error' => $logEx->getMessage()
                ]);
            }
        }
    }
    
    /**
     * Download a file from S3 (TrueFire bucket).
     * Note: TrueFire S3 files have incorrect Content-Encoding header (UTF-8),
     * so we need to disable automatic content decoding.
     */
    private function downloadFromS3(string $bucket, string $key, string $targetPath): void
    {
        $config = [
            'version' => 'latest',
            'region' => 'us-east-1',
            // Disable automatic content decoding to handle files with invalid Content-Encoding headers
            'http' => [
                'decode_content' => false,
            ],
        ];
        
        // Use profile locally, ECS task role in production
        $profile = env('TRUEFIRE_AWS_PROFILE');
        if (!empty($profile)) {
            $config['profile'] = $profile;
        }
        
        $s3Client = new S3Client($config);
        
        Log::info('Starting S3 download', [
            'bucket' => $bucket,
            'key' => $key,
            'target' => $targetPath
        ]);
        
        $result = $s3Client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SaveAs' => $targetPath,
            '@http' => [
                'decode_content' => false,
            ],
        ]);
        
        Log::info('S3 download completed', [
            'bucket' => $bucket,
            'key' => $key,
            'content_length' => $result['ContentLength'] ?? 0
        ]);
    }
} 