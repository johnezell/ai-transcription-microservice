<?php

namespace App\Jobs;

use App\Models\Video;
use App\Models\TranscriptionLog;
use App\Http\Controllers\Api\TranscriptionController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use Aws\Sqs\SqsClient;

class ProcessCallbackQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $queueUrl = env('CALLBACK_QUEUE_URL');
        
        if (empty($queueUrl)) {
            Log::error('[ProcessCallbackQueueJob] Callback queue URL is not defined.');
            return;
        }

        Log::info('[ProcessCallbackQueueJob] Starting to process SQS callback queue', [
            'queue_url' => $queueUrl
        ]);

        try {
            $sqsClient = $this->getSqsClient();
            
            // Process up to 10 messages at a time
            $maxMessagesToProcess = 10;
            $messagesProcessed = 0;
            
            do {
                $receiveParams = [
                    'QueueUrl' => $queueUrl,
                    'MaxNumberOfMessages' => min(10, $maxMessagesToProcess - $messagesProcessed),
                    'WaitTimeSeconds' => 5,
                    'VisibilityTimeout' => 60
                ];
                
                $result = $sqsClient->receiveMessage($receiveParams);
                $messages = $result->get('Messages');
                
                if (!$messages) {
                    Log::info('[ProcessCallbackQueueJob] No messages found in queue');
                    break;
                }
                
                foreach ($messages as $message) {
                    Log::info('[ProcessCallbackQueueJob] Processing callback message', [
                        'message_id' => $message['MessageId']
                    ]);
                    
                    try {
                        $body = json_decode($message['Body'], true);
                        
                        if (isset($body['job_id']) && isset($body['status'])) {
                            $this->processCallback($body);
                        } else {
                            Log::warning('[ProcessCallbackQueueJob] Invalid message format', [
                                'message_id' => $message['MessageId'],
                                'body' => $body
                            ]);
                        }
                        
                        // Delete the message from the queue regardless of success
                        // If we don't delete it, it will become visible again after the visibility timeout
                        $sqsClient->deleteMessage([
                            'QueueUrl' => $queueUrl,
                            'ReceiptHandle' => $message['ReceiptHandle']
                        ]);
                        
                        $messagesProcessed++;
                    } catch (\Exception $e) {
                        Log::error('[ProcessCallbackQueueJob] Error processing callback message', [
                            'message_id' => $message['MessageId'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            } while ($messages && $messagesProcessed < $maxMessagesToProcess);
            
            Log::info('[ProcessCallbackQueueJob] Finished processing queue', [
                'messages_processed' => $messagesProcessed
            ]);
            
            // If we processed messages and there might be more, dispatch another job
            if ($messagesProcessed > 0 && $messagesProcessed == $maxMessagesToProcess) {
                self::dispatch()->delay(now()->addSeconds(5));
            }
        } catch (\Exception $e) {
            Log::error('[ProcessCallbackQueueJob] Exception during queue processing', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Process a callback message from a microservice
     *
     * @param array $data The message data
     * @return void
     */
    protected function processCallback(array $data)
    {
        $jobId = $data['job_id'];
        $status = $data['status'];
        
        Log::info('[ProcessCallbackQueueJob] Processing callback for job', [
            'job_id' => $jobId,
            'status' => $status
        ]);
        
        $video = Video::find($jobId);
        
        if (!$video) {
            Log::error('[ProcessCallbackQueueJob] Video not found', [
                'job_id' => $jobId
            ]);
            return;
        }
        
        // Create a request object to pass to the TranscriptionController
        $request = new Request();
        $request->merge([
            'status' => $status,
            'completed_at' => $data['completed_at'] ?? now()->toIso8601String(),
            'response_data' => $data['response_data'] ?? null,
            'error_message' => $data['error_message'] ?? null
        ]);
        
        try {
            $controller = new TranscriptionController();
            $response = $controller->updateJobStatus($jobId, $request);
            
            Log::info('[ProcessCallbackQueueJob] Successfully processed callback', [
                'job_id' => $jobId,
                'status' => $status,
                'response_status' => $response->status()
            ]);
        } catch (\Exception $e) {
            Log::error('[ProcessCallbackQueueJob] Error calling updateJobStatus', [
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
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
} 