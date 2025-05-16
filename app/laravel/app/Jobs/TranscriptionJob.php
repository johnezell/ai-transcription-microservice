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
        /* if (App::environment('local')) {
            Log::info('[TranscriptionJob LOCAL] Simulating transcription success.', ['video_id' => $this->video->id]);
            $baseS3Key = 's3/jobs/' . $this->video->id . '/';
            $dummyTranscriptTxtKey = $baseS3Key . 'mock_local_transcript.txt';
            $dummyTranscriptSrtKey = $baseS3Key . 'mock_local_transcript.srt';
            $dummyTranscriptJsonKey = $baseS3Key . 'mock_local_transcript.json';
            $mockedText = "This is a locally mocked transcript for video {$this->video->id}. Lorem ipsum dolor sit amet.";
            $mockedSegments = [['text' => 'Mocked segment 1.', 'start' => 0, 'end' => 2], ['text' => 'Mocked segment 2.', 'start' => 2, 'end' => 4]];
            
            if (!Storage::disk('s3')->exists($dummyTranscriptTxtKey)) { Storage::disk('s3')->put($dummyTranscriptTxtKey, $mockedText, ['ACL' => 'bucket-owner-full-control']); }
            if (!Storage::disk('s3')->exists($dummyTranscriptSrtKey)) { Storage::disk('s3')->put($dummyTranscriptSrtKey, "1\n00:00:00,000 --> 00:00:02,000\nMocked segment 1.\n\n2\n00:00:02,000 --> 00:00:04,000\nMocked segment 2.\n", ['ACL' => 'bucket-owner-full-control']); }
            if (!Storage::disk('s3')->exists($dummyTranscriptJsonKey)) { Storage::disk('s3')->put($dummyTranscriptJsonKey, json_encode(['text' => $mockedText, 'segments' => $mockedSegments, 'language' => 'en']), ['ACL' => 'bucket-owner-full-control']); }

            $this->video->update([
                'status' => 'transcribed',
                'transcript_path' => $dummyTranscriptTxtKey,
                // 'transcript_srt_path' => $dummyTranscriptSrtKey, // Ensure these fields exist in your Video model and migrations if you use them
                // 'transcript_json_path' => $dummyTranscriptJsonKey, // Ensure these fields exist in your Video model and migrations if you use them
                'transcript_text' => $mockedText,
                'transcript_json' => ['text' => $mockedText, 'segments' => $mockedSegments, 'language' => 'en'],
            ]);

            $log = TranscriptionLog::where('video_id', $this->video->id)->first();
            if ($log) {
                $log->update([
                    'status' => 'transcribed',
                    'transcription_started_at' => $log->transcription_started_at ?? now()->subSecond(),
                    'transcription_completed_at' => now(),
                    'progress_percentage' => 75, 
                ]);
            }
            
            Log::info('[TranscriptionJob LOCAL] Dispatching TerminologyRecognitionJob locally.', ['video_id' => $this->video->id]);
            TerminologyRecognitionJob::dispatch($this->video);
            return; 
        }*/

        if (empty($this->video->audio_path)) {
            Log::error('[TranscriptionJob] Audio path is empty, cannot start transcription.', ['video_id' => $this->video->id]);
            $this->video->update(['status' => 'failed', 'error_message' => 'Audio path missing for transcription.']);
            return;
        }

        // Update video status to 'transcribing'
        $this->video->update(['status' => 'transcribing']);
        
        $transcriptionLog = TranscriptionLog::firstOrCreate(
            ['video_id' => $this->video->id],
            ['job_id' => $this->video->id, 'started_at' => now()]
        );
        $transcriptionLog->update([
            'status' => 'transcribing',
            'transcription_started_at' => now(),
            'progress_percentage' => 60 // Example progress: Transcription process initiated
        ]);

        $queueUrl = env('TRANSCRIPTION_QUEUE_URL');
        
        if (empty($queueUrl)) {
            Log::error('[TranscriptionJob] Transcription queue URL is not defined.', ['video_id' => $this->video->id]);
            $this->failJob('Transcription queue URL is not defined.');
            return;
        }

        $messageBody = json_encode([
            'job_id' => (string) $this->video->id,
            'audio_s3_key' => $this->video->audio_path, // Pass the S3 key of the audio file
            'model_name' => 'base', // Or make this configurable, e.g., via $this->video->model_preference
            'timestamp' => now()->toIso8601String()
        ]);

        try {
            Log::info('[TranscriptionJob] Sending message to Transcription SQS queue.', [
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
                        'StringValue' => 'transcription',
                    ],
                ],
            ]);

            Log::info('[TranscriptionJob] Successfully sent message to Transcription SQS queue.', [
                'video_id' => $this->video->id,
                'message_id' => $result->get('MessageId')
            ]);
        } catch (\Exception $e) {
            Log::error('[TranscriptionJob] Exception sending message to Transcription SQS queue.', [
                'video_id' => $this->video->id,
                'error' => $e->getMessage()
            ]);
            $this->failJob('Exception sending message to Transcription SQS queue: ' . $e->getMessage());
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
        $transcriptionLog = TranscriptionLog::where('video_id', $this->video->id)->first();
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