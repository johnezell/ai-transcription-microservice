<?php

namespace App\Jobs;

use App\Models\TranscriptionLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessTranscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The transcription log instance.
     *
     * @var \App\Models\TranscriptionLog
     */
    protected $transcriptionLog;

    /**
     * Create a new job instance.
     */
    public function __construct(TranscriptionLog $transcriptionLog)
    {
        $this->transcriptionLog = $transcriptionLog;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Update status to processing
        $this->transcriptionLog->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            $pythonServiceUrl = env('PYTHON_SERVICE_URL', 'http://transcription-service:5000');
            
            // Log that we're attempting to connect to the Python service
            Log::info('Attempting to connect to Python service', [
                'job_id' => $this->transcriptionLog->job_id,
                'service_url' => $pythonServiceUrl
            ]);

            // Send request to the Python service
            $response = Http::post("{$pythonServiceUrl}/process", [
                'job_id' => $this->transcriptionLog->job_id,
                'data' => $this->transcriptionLog->request_data
            ]);

            if ($response->successful()) {
                Log::info('Successfully sent job to Python service', [
                    'job_id' => $this->transcriptionLog->job_id,
                    'response' => $response->json()
                ]);

                // The Python service will update the job status via callback
                // But we'll update it here as well to "processing" since we know
                // the Python service has received the job
                $this->transcriptionLog->update([
                    'status' => 'processing',
                    'response_data' => [
                        'message' => 'Job sent to Python service',
                        'timestamp' => now()->toIso8601String(),
                        'python_response' => $response->json()
                    ],
                ]);
            } else {
                throw new \Exception('Python service returned error: ' . $response->body());
            }

            Log::info('Transcription job dispatch completed', [
                'job_id' => $this->transcriptionLog->job_id
            ]);
        } catch (\Exception $e) {
            // Log the error and update the transcription log
            Log::error('Failed to process transcription job', [
                'job_id' => $this->transcriptionLog->job_id,
                'error' => $e->getMessage()
            ]);

            $this->transcriptionLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }
}
