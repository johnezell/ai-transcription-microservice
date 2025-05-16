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
use Aws\Sqs\SqsClient;

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

        $queueUrl = env('AUDIO_EXTRACTION_QUEUE_URL');
        
        if (empty($queueUrl)) {
            Log::error('[AudioExtractionJob] Audio extraction queue URL is not defined.', ['video_id' => $this->video->id]);
            $this->failJob('Audio extraction queue URL is not defined.');
            return;
        }

        $messageBody = json_encode([
            'job_id' => (string) $this->video->id,
            'video_s3_key' => $this->video->storage_path,
            'app_data_bucket' => env('AWS_BUCKET'),
            'timestamp' => now()->toIso8601String()
        ]);

        try {
            Log::info('[AudioExtractionJob] Sending message to Audio Extraction SQS queue.', [
                'video_id' => $this->video->id,
                'queue_url' => $queueUrl,
                'message_contents' => json_decode($messageBody, true)
            ]);

            $sqsClient = $this->getSqsClient();
            $result = $sqsClient->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => $messageBody,
                'MessageAttributes' => [
                    'JobType' => [
                        'DataType' => 'String',
                        'StringValue' => 'audio_extraction',
                    ],
                ],
            ]);

            Log::info('[AudioExtractionJob] Successfully sent message to Audio Extraction SQS queue.', [
                'video_id' => $this->video->id,
                'message_id' => $result->get('MessageId')
            ]);
        } catch (\Exception $e) {
            Log::error('[AudioExtractionJob] Exception sending message to Audio Extraction SQS queue.', [
                'video_id' => $this->video->id,
                'error' => $e->getMessage()
            ]);
            $this->failJob('Exception sending message to Audio Extraction SQS queue: ' . $e->getMessage());
        }
    }

    /**
     * Get SQS client instance.
     *
     * @return \Aws\Sqs\SqsClient
     */
    protected function getSqsClient(): SqsClient
    {
        return new SqsClient([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ]);
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