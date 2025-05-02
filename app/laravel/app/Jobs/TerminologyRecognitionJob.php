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

class TerminologyRecognitionJob implements ShouldQueue
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
            // Make sure the video has a transcript
            if (empty($this->video->transcript_path)) {
                Log::error('Transcript not found for terminology recognition job', [
                    'video_id' => $this->video->id
                ]);
                
                throw new \Exception('No transcript available for terminology recognition');
            }
            
            // Update status to indicate we're processing terminology
            $this->video->update([
                'status' => 'processing_music_terms' // Using the old status name for backward compatibility
            ]);
            
            // Get or create transcription log
            $log = \App\Models\TranscriptionLog::firstOrCreate(
                ['video_id' => $this->video->id],
                [
                    'job_id' => $this->video->id,
                    'status' => 'processing_music_terms',
                    'started_at' => now(),
                ]
            );
            
            // Update terminology recognition start time
            $termStartTime = now();
            $log->update([
                'music_term_recognition_started_at' => $termStartTime, // Using the old column name for backward compatibility
                'status' => 'processing_music_terms',
                'progress_percentage' => 85 // Terminology recognition started, about 85% through whole process
            ]);
            
            // Get the terminology service URL from environment
            $serviceUrl = env('MUSIC_TERM_SERVICE_URL', 'http://music-term-recognition-service:5000');
            
            // Log the request with additional context
            Log::info('Dispatching terminology recognition request to service', [
                'video_id' => $this->video->id,
                'service_url' => $serviceUrl,
                'transcript_path' => $this->video->transcript_path,
                'job_class' => 'TerminologyRecognitionJob'
            ]);

            // Send request to the terminology recognition service
            $response = Http::timeout(180)->post("{$serviceUrl}/process", [
                'job_id' => (string) $this->video->id
            ]);

            if ($response->successful()) {
                Log::info('Successfully dispatched terminology recognition request', [
                    'video_id' => $this->video->id,
                    'response' => $response->json()
                ]);
                
                // Update progress - terminology service will call back on completion
                $log->update([
                    'progress_percentage' => 90 // Terminology recognition in progress
                ]);
                
                // NOTE: The actual terminology processing happens asynchronously
                // and updates will come through the callback endpoint
                // The video will be marked as 'completed' when the callback is received
                // from the terminology service
                
            } else {
                $errorMessage = 'Terminology recognition service returned error: ' . $response->body();
                Log::error($errorMessage, [
                    'video_id' => $this->video->id
                ]);
                
                // Mark video as completed since terminology is optional
                $this->video->update([
                    'status' => 'completed', 
                    'error_message' => $errorMessage
                ]);
                
                $termEndTime = now();
                $termDuration = $termEndTime->diffInSeconds($termStartTime);
                
                // Calculate total duration from start
                $totalDuration = 0;
                if ($log->started_at) {
                    $totalDuration = $termEndTime->diffInSeconds($log->started_at);
                }
                
                $log->update([
                    'status' => 'completed',
                    'error_message' => $errorMessage,
                    'music_term_recognition_completed_at' => $termEndTime,
                    'music_term_recognition_duration_seconds' => $termDuration,
                    'completed_at' => $termEndTime,
                    'total_processing_duration_seconds' => $totalDuration,
                    'progress_percentage' => 100
                ]);
            }
        } catch (\Exception $e) {
            $errorMessage = 'Exception in terminology recognition job: ' . $e->getMessage();
            Log::error($errorMessage, [
                'video_id' => $this->video->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Update video to completed status since terminology recognition is optional
            $this->video->update([
                'status' => 'completed',
                'error_message' => $errorMessage
            ]);
            
            // Try to update log with timing information
            try {
                $log = \App\Models\TranscriptionLog::where('video_id', $this->video->id)->first();
                if ($log) {
                    $endTime = now();
                    $startTime = $log->music_term_recognition_started_at ?? $log->started_at ?? $endTime;
                    $duration = $endTime->diffInSeconds($startTime);
                    
                    // Calculate total duration from start
                    $totalDuration = 0;
                    if ($log->started_at) {
                        $totalDuration = $endTime->diffInSeconds($log->started_at);
                    }
                    
                    $log->update([
                        'status' => 'completed',
                        'error_message' => $errorMessage,
                        'music_term_recognition_completed_at' => $endTime,
                        'music_term_recognition_duration_seconds' => $duration,
                        'completed_at' => $endTime,
                        'total_processing_duration_seconds' => $totalDuration,
                        'progress_percentage' => 100
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
} 