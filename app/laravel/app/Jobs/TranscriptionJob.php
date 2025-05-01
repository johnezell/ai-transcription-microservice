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

class TranscriptionJob implements ShouldQueue
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
            // Update video status to transcribing
            $this->video->update(['status' => 'transcribing']);
            
            // Get or create transcription log
            $log = \App\Models\TranscriptionLog::firstOrCreate(
                ['video_id' => $this->video->id],
                [
                    'job_id' => $this->video->id,
                    'status' => 'transcribing',
                    'started_at' => now(),
                ]
            );
            
            // Update transcription start time
            $transcriptionStartTime = now();
            $log->update([
                'transcription_started_at' => $transcriptionStartTime,
                'status' => 'transcribing',
                'progress_percentage' => 60 // Transcription started, about 60% through the whole process
            ]);
            
            // Get the transcription service URL from environment
            $transcriptionServiceUrl = env('TRANSCRIPTION_SERVICE_URL', 'http://transcription-service:5000');
            
            // Log the request
            Log::info('Dispatching transcription request to service', [
                'video_id' => $this->video->id,
                'service_url' => $transcriptionServiceUrl
            ]);

            // Send request to the transcription service
            $response = Http::timeout(180)->post("{$transcriptionServiceUrl}/process", [
                'job_id' => (string) $this->video->id
            ]);

            if ($response->successful()) {
                Log::info('Successfully dispatched transcription request', [
                    'video_id' => $this->video->id,
                    'response' => $response->json()
                ]);
                
                // Update progress - transcription service will call back on completion
                $log->update([
                    'progress_percentage' => 75 // Transcription in progress
                ]);
            } else {
                $errorMessage = 'Transcription service returned error: ' . $response->body();
                Log::error($errorMessage, [
                    'video_id' => $this->video->id
                ]);
                
                // Update video with failure
                $this->video->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage
                ]);
                
                $transcriptionEndTime = now();
                $transcriptionDuration = $transcriptionEndTime->diffInSeconds($transcriptionStartTime);
                
                $log->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                    'transcription_completed_at' => $transcriptionEndTime,
                    'transcription_duration_seconds' => $transcriptionDuration,
                    'completed_at' => $transcriptionEndTime,
                    'progress_percentage' => 0
                ]);
                
                // Calculate total duration if possible
                if ($log->started_at) {
                    $totalDuration = $transcriptionEndTime->diffInSeconds($log->started_at);
                    $log->update([
                        'total_processing_duration_seconds' => $totalDuration
                    ]);
                }
            }
        } catch (\Exception $e) {
            $errorMessage = 'Exception in transcription job: ' . $e->getMessage();
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
                    $startTime = $log->transcription_started_at ?? $log->started_at ?? $endTime;
                    $duration = $endTime->diffInSeconds($startTime);
                    
                    $log->update([
                        'status' => 'failed',
                        'error_message' => $errorMessage,
                        'transcription_completed_at' => $endTime,
                        'transcription_duration_seconds' => $duration,
                        'completed_at' => $endTime,
                        'progress_percentage' => 0
                    ]);
                    
                    // Calculate total duration if possible
                    if ($log->started_at && $log->started_at != $startTime) {
                        $totalDuration = $endTime->diffInSeconds($log->started_at);
                        $log->update([
                            'total_processing_duration_seconds' => $totalDuration
                        ]);
                    }
                }
            } catch (\Exception $logEx) {
                Log::error('Failed to update transcription log', [
                    'video_id' => $this->video->id,
                    'error' => $logEx->getMessage()
                ]);
            }
        }
    }
} 