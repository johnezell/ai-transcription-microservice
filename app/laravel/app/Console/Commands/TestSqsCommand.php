<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Aws\Sqs\SqsClient;

class TestSqsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sqs:test {queue? : Queue URL environment variable name (default: AUDIO_EXTRACTION_QUEUE_URL)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test SQS messaging to verify connectivity and permissions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $queueEnvVar = $this->argument('queue') ?: 'AUDIO_EXTRACTION_QUEUE_URL';
        $queueUrl = env($queueEnvVar);
        
        if (!$queueUrl) {
            $this->error("Queue URL not found in environment - {$queueEnvVar} not set");
            return 1;
        }
        
        $this->info("Testing SQS connection to: {$queueUrl}");
        $this->info("AWS Region: " . env('AWS_DEFAULT_REGION', 'us-east-1'));
        
        // Create SQS client
        try {
            $sqsClient = new SqsClient([
                'version' => 'latest',
                'region' => env('AWS_DEFAULT_REGION', 'us-east-1')
                // No explicit credentials - will use environment or container role
            ]);
            
            $this->info("SQS client created successfully");
        } catch (\Exception $e) {
            $this->error("Failed to create SQS client: " . $e->getMessage());
            return 1;
        }
        
        // Attempt to send a test message
        try {
            $messageBody = json_encode([
                'job_id' => 'test-local-' . time(),
                'test_message' => true,
                'environment' => app()->environment(),
                'timestamp' => now()->toIso8601String()
            ]);
            
            $this->info("Sending message: " . $messageBody);
            
            $result = $sqsClient->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => $messageBody,
                'MessageAttributes' => [
                    'TestSource' => [
                        'DataType' => 'String',
                        'StringValue' => 'Laravel-Test-Command'
                    ]
                ]
            ]);
            
            $this->info("Message sent successfully!");
            $this->info("MessageId: " . $result->get('MessageId'));
            
            // Also try to receive a message if it's the callback queue
            if (str_contains(strtolower($queueEnvVar), 'callback')) {
                $this->info("Testing message reception from the callback queue...");
                
                $receiveResult = $sqsClient->receiveMessage([
                    'QueueUrl' => $queueUrl,
                    'MaxNumberOfMessages' => 1,
                    'WaitTimeSeconds' => 5
                ]);
                
                $messages = $receiveResult->get('Messages');
                if ($messages) {
                    $this->info("Received a message! Receipt handle: " . $messages[0]['ReceiptHandle']);
                    
                    // Don't delete the message so we can see it in the queue
                    $this->info("Message body: " . $messages[0]['Body']);
                } else {
                    $this->info("No messages found in the queue (that's normal if the queue is empty)");
                }
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to send message: " . $e->getMessage());
            $this->warn("AWS Error Details:");
            $this->warn("  Type: " . get_class($e));
            
            // Check if this is an Aws specific exception with more details
            if ($e instanceof \Aws\Exception\AwsException) {
                $this->warn("  AWS Error Code: " . $e->getAwsErrorCode());
                $this->warn("  AWS Error Message: " . $e->getAwsErrorMessage());
            }
            
            $this->warn("Check that:");
            $this->warn("  1. Your AWS credentials have SQS permissions");
            $this->warn("  2. The queue exists and is accessible from your network");
            $this->warn("  3. You're using the correct region for the queue");
            
            return 1;
        }
    }
} 