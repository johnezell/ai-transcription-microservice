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

class TerminologyRecognitionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Video $video;

    /**
     * Create a new job instance.
     *
     * @param \App\Models\Video $video
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
            Log::info('[TerminologyRecognitionJob LOCAL] Simulating terminology recognition success.', ['video_id' => $this->video->id]);
            $baseS3Key = 's3/jobs/' . $this->video->id . '/';
            $dummyTerminologyJsonKey = $baseS3Key . 'mock_local_terminology.json';
            $mockedTermData = [
                'method' => 'mock_regex_v1',
                'total_unique_terms' => rand(2, 10),
                'total_term_occurrences' => rand(5, 20),
                'category_summary' => ['Technology' => rand(1,3), 'Dev Concepts' => rand(1,2)],
                'terms' => [
                    ['term' => 'laravel', 'category_slug' => 'technology', 'count' => rand(1,2)],
                    ['term' => 'php', 'category_slug' => 'technology', 'count' => rand(1,3)],
                ]
            ];
            if (!Storage::disk('s3')->exists($dummyTerminologyJsonKey)) { 
                Storage::disk('s3')->put($dummyTerminologyJsonKey, json_encode($mockedTermData), ['ACL' => 'bucket-owner-full-control']); 
            }

            $this->video->update([
                'status' => 'completed', // This is the final step in the mock chain
                'terminology_path' => $dummyTerminologyJsonKey,
                'terminology_json' => $mockedTermData['terms'], // Storing the example terms list
                'has_terminology' => true,
                'terminology_count' => $mockedTermData['total_term_occurrences'],
                'terminology_metadata' => ['category_summary' => $mockedTermData['category_summary']]
            ]);

            $log = TranscriptionLog::where('video_id', $this->video->id)->first();
            if ($log) {
                $log->update([
                    'status' => 'completed',
                    'terminology_analysis_started_at' => $log->terminology_analysis_started_at ?? now()->subSecond(),
                    'terminology_analysis_completed_at' => now(), 
                    'terminology_term_count' => $mockedTermData['total_term_occurrences'], 
                    'completed_at' => now(),
                    'progress_percentage' => 100
                ]);
            }
            Log::info('[TerminologyRecognitionJob LOCAL] Mock processing complete. Video status set to completed.', ['video_id' => $this->video->id]);
            return;
        } */

        if (empty($this->video->transcript_path)) {
            Log::error('[TerminologyRecognitionJob] Transcript path is empty for video.', ['video_id' => $this->video->id]);
            $this->failJob('Transcript path missing for terminology recognition.');
            return;
        }

        // Update video status to 'processing_terminology' (or a new generic status)
        $this->video->update(['status' => 'processing_terminology']);
        
        $log = TranscriptionLog::firstOrCreate(
            ['video_id' => $this->video->id],
            ['job_id' => $this->video->id, 'started_at' => $this->video->transcriptionLog->started_at ?? now()]
        );
        $log->update([
            'status' => 'processing_terminology',
            'terminology_analysis_started_at' => now(),
            'progress_percentage' => 85 
        ]);

        $terminologyServiceUrl = env('TERMINOLOGY_SERVICE_URL');
        $payload = [
            'job_id' => (string) $this->video->id,
            'transcript_s3_key' => $this->video->transcript_path, 
        ];

        Log::info('[TerminologyRecognitionJob] Dispatching request to Terminology Service.', [
            'video_id' => $this->video->id,
            'service_url' => $terminologyServiceUrl,
            'payload' => $payload
        ]);

        try {
            $response = Http::timeout(300) // Timeout for terminology processing
                            ->post("{$terminologyServiceUrl}/process", $payload);

            if ($response->successful()) {
                Log::info('[TerminologyRecognitionJob] Successfully dispatched request to Terminology Service.', [
                    'video_id' => $this->video->id,
                    'response_status' => $response->status(),
                ]);
                // Actual status update to 'terminology_extracted' or 'completed' will happen via callback
            } else {
                $errorMessage = 'Terminology service returned an error.';
                try { $errorMessage .= ' Status: ' . $response->status() . ' Body: ' . $response->body(); } catch (\Exception $_) {}
                Log::error($errorMessage, ['video_id' => $this->video->id]);
                $this->failJob($errorMessage);
            }
        } catch (\Exception $e) {
            Log::error('[TerminologyRecognitionJob] Exception calling Terminology Service.', [
                'video_id' => $this->video->id,
                'error' => $e->getMessage()
            ]);
            $this->failJob('Exception calling Terminology Service: ' . $e->getMessage());
        }
    }

    protected function failJob(string $errorMessage): void
    {
        $this->video->update(['status' => 'failed', 'error_message' => $errorMessage]);
        $log = TranscriptionLog::where('video_id', $this->video->id)->first();
        if ($log) {
            $log->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
                'completed_at' => now()
            ]);
        }
    }
} 