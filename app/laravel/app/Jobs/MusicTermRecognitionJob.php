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

class MusicTermRecognitionJob implements ShouldQueue
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
                Log::error('Transcript not found for music term recognition job', [
                    'video_id' => $this->video->id
                ]);
                
                throw new \Exception('No transcript available for music term recognition');
            }
            
            // Update status to indicate we're processing music terms
            $this->video->update([
                'status' => 'processing_music_terms'
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
            
            // Update music term recognition start time
            $musicTermStartTime = now();
            $log->update([
                'music_term_recognition_started_at' => $musicTermStartTime,
                'status' => 'processing_music_terms',
                'progress_percentage' => 85 // Music term recognition started, about 85% through whole process
            ]);
            
            // Get the music term service URL from environment
            $musicTermServiceUrl = env('MUSIC_TERM_SERVICE_URL', 'http://music-term-recognition-service:5000');
            
            // Log the request
            Log::info('Dispatching music term recognition request to service', [
                'video_id' => $this->video->id,
                'service_url' => $musicTermServiceUrl
            ]);

            // Send request to the music term recognition service
            $response = Http::timeout(180)->post("{$musicTermServiceUrl}/process", [
                'job_id' => (string) $this->video->id
            ]);

            if ($response->successful()) {
                Log::info('Successfully dispatched music term recognition request', [
                    'video_id' => $this->video->id,
                    'response' => $response->json()
                ]);
                
                // Update progress - music term service will call back on completion
                $log->update([
                    'progress_percentage' => 90 // Music term recognition in progress
                ]);
            } else {
                $errorMessage = 'Music term recognition service returned error: ' . $response->body();
                Log::error($errorMessage, [
                    'video_id' => $this->video->id
                ]);
                
                // Update video with failure
                $this->video->update([
                    'status' => 'completed', // Still mark as completed since this is optional
                    'error_message' => $errorMessage
                ]);
                
                $musicTermEndTime = now();
                $musicTermDuration = $musicTermEndTime->diffInSeconds($musicTermStartTime);
                
                $log->update([
                    'status' => 'completed', // Still mark as completed since this is optional
                    'error_message' => $errorMessage,
                    'music_term_recognition_completed_at' => $musicTermEndTime,
                    'music_term_recognition_duration_seconds' => $musicTermDuration
                ]);
            }
        } catch (\Exception $e) {
            $errorMessage = 'Exception in music term recognition job: ' . $e->getMessage();
            Log::error($errorMessage, [
                'video_id' => $this->video->id,
                'error' => $e->getMessage()
            ]);
            
            // Update video to completed status since music term recognition is optional
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
                    
                    $log->update([
                        'status' => 'completed',
                        'error_message' => $errorMessage,
                        'music_term_recognition_completed_at' => $endTime,
                        'music_term_recognition_duration_seconds' => $duration
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