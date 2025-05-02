<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTerminologyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The video instance.
     *
     * @var \App\Models\Video
     */
    protected $video;

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
    public function handle()
    {
        try {
            // Check if the video is still in a valid state for terminology processing
            $freshVideo = Video::find($this->video->id);
            
            // Only proceed if the video is in transcribed state - avoid race conditions and repeated processing
            if (!$freshVideo || $freshVideo->status !== 'transcribed') {
                Log::info('Skipping terminology processing - video not in transcribed state', [
                    'video_id' => $this->video->id,
                    'status' => $freshVideo ? $freshVideo->status : 'not_found'
                ]);
                return;
            }
            
            // Check if video has transcript path
            if (empty($freshVideo->transcript_path) || !file_exists($freshVideo->transcript_path)) {
                Log::warning('Cannot process terminology - transcript file missing', [
                    'video_id' => $freshVideo->id,
                    'transcript_path' => $freshVideo->transcript_path ?? 'null'
                ]);
                
                // Mark as completed despite missing transcript
                $freshVideo->update(['status' => 'completed']);
                return;
            }
            
            Log::info('Processing terminology recognition from job queue', [
                'video_id' => $freshVideo->id,
                'transcript_path' => $freshVideo->transcript_path,
                'transcript_exists' => file_exists($freshVideo->transcript_path)
            ]);
            
            // Use the TerminologyController to handle the terminology recognition
            $controller = new \App\Http\Controllers\Api\TerminologyController();
            $request = new \Illuminate\Http\Request();
            
            // Trigger the process method
            $response = $controller->process($request, $freshVideo->id);
            
            if ($response && $response->status() === 200) {
                $responseData = json_decode($response->getContent(), true);
                Log::info('Successfully started terminology recognition through job queue', [
                    'video_id' => $freshVideo->id,
                    'response' => $responseData
                ]);
                
                // Check if we got a synchronous response with results
                if (isset($responseData['data']) && isset($responseData['data']['music_terms_json_path'])) {
                    // The terminology service returned results immediately - verify database state
                    $updatedVideo = Video::find($freshVideo->id);
                    
                    // If the video is still in transcribed state, force to completed
                    if ($updatedVideo && $updatedVideo->status === 'transcribed') {
                        Log::info('Forcing video to completed state after synchronous terminology response', [
                            'video_id' => $updatedVideo->id,
                            'music_terms_path' => $responseData['data']['music_terms_json_path']
                        ]);
                        
                        // Update with terminology data and completed status
                        $updatedVideo->update([
                            'status' => 'completed',
                            'terminology_path' => $responseData['data']['music_terms_json_path'],
                            'has_terminology' => true
                        ]);
                    }
                }
            } else {
                // If the controller call failed, update the video status to completed
                Log::error('Failed to process terminology recognition through job', [
                    'video_id' => $freshVideo->id,
                    'response' => $response ? json_decode($response->getContent(), true) : null
                ]);
                
                // Mark as completed since terminology recognition failed
                $freshVideo->update(['status' => 'completed']);
            }
        } catch (\Exception $e) {
            Log::error('Exception in ProcessTerminologyJob', [
                'video_id' => $this->video->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Mark as completed despite the error
            try {
                $freshVideo = Video::find($this->video->id);
                if ($freshVideo) {
                    $freshVideo->update(['status' => 'completed']);
                }
            } catch (\Exception $innerEx) {
                Log::error('Failed to update video status after terminology job error', [
                    'video_id' => $this->video->id,
                    'error' => $innerEx->getMessage()
                ]);
            }
        }
    }
} 