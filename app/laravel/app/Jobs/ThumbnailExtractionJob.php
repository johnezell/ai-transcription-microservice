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
use RuntimeException;
use Aws\Sqs\SqsClient;

class ThumbnailExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $video;
    protected $videoKey;
    protected $outputKey;

    /**
     * Create a new job instance.
     */
    public function __construct(Video $video, string $videoKey, string $outputKey)
    {
        $this->video = $video;
        $this->videoKey = $videoKey;
        $this->outputKey = $outputKey;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Starting thumbnail extraction for video ID: {$this->video->id}");

        // Check if we're using SQS or direct HTTP
        $queueUrl = env('AUDIO_EXTRACTION_QUEUE_URL');
        
        if ($queueUrl) {
            $this->sendMessageToSQS($queueUrl);
        } else {
            $this->sendDirectRequest();
        }
    }

    /**
     * Send a message to the SQS queue
     */
    protected function sendMessageToSQS(string $queueUrl): void
    {
        Log::info("Sending thumbnail extraction job to SQS for video {$this->video->id}");
        
        try {
            $sqsClient = new SqsClient([
                'version' => 'latest',
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1')
            ]);
            
            $messageBody = json_encode([
                'job_type' => 'thumbnail',
                'video_id' => $this->video->id,
                'video_s3_key' => $this->videoKey,
                'output_s3_key' => $this->outputKey,
                'callback_url' => route('api.videos.thumbnail.callback', ['id' => $this->video->id]),
            ]);
            
            $result = $sqsClient->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => $messageBody,
                'MessageAttributes' => [
                    'JobType' => [
                        'DataType' => 'String',
                        'StringValue' => 'thumbnail',
                    ],
                ]
            ]);
            
            Log::info("Thumbnail extraction message sent to SQS with ID: {$result['MessageId']}");
        } catch (\Exception $e) {
            Log::error("Failed to send message to SQS for thumbnail extraction: {$e->getMessage()}");
            throw new RuntimeException("Failed to send message to SQS for thumbnail extraction: {$e->getMessage()}");
        }
    }

    /**
     * Send a direct HTTP request to the audio extraction service
     */
    protected function sendDirectRequest(): void
    {
        Log::info("Sending direct HTTP request for thumbnail extraction for video {$this->video->id}");
        
        $audioServiceUrl = env('AUDIO_SERVICE_URL', 'http://localhost:5000');
        $endpoint = "{$audioServiceUrl}/api/thumbnails";
        
        try {
            $response = Http::timeout(5)->post($endpoint, [
                'video_id' => $this->video->id,
                'video_s3_key' => $this->videoKey,
                'output_s3_key' => $this->outputKey,
                'callback_url' => route('api.videos.thumbnail.callback', ['id' => $this->video->id]),
            ]);
            
            if ($response->failed()) {
                Log::error("Thumbnail extraction request failed with status: {$response->status()}, body: {$response->body()}");
                throw new RuntimeException("Failed to request thumbnail extraction: {$response->status()} - {$response->body()}");
            }
            
            Log::info("Thumbnail extraction request sent successfully: {$response->body()}");
        } catch (\Exception $e) {
            Log::error("Exception while requesting thumbnail extraction: {$e->getMessage()}");
            throw new RuntimeException("Failed to request thumbnail extraction: {$e->getMessage()}");
        }
    }
} 