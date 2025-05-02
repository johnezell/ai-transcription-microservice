<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Video;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessTerminologyJob;

class ProcessVideoTerminology extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'videos:process-terminology {id : The ID of the video to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually process terminology recognition for a video';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $videoId = $this->argument('id');
        $this->info("Processing terminology for video: {$videoId}");
        
        // Log this action to the main Laravel log for debugging
        Log::info("Manual terminology processing initiated via command", [
            'video_id' => $videoId,
            'command' => 'ProcessVideoTerminology',
            'timestamp' => now()->toDateTimeString()
        ]);
        
        try {
            // Find the video
            $video = Video::find($videoId);
            
            if (!$video) {
                $this->error("Video not found: {$videoId}");
                return 1;
            }
            
            $this->info("Video status: {$video->status}");
            $this->info("Has transcript: " . (!empty($video->transcript_path) ? 'Yes' : 'No'));
            
            if (empty($video->transcript_path)) {
                $this->error("Video has no transcript, cannot process terminology");
                return 1;
            }
            
            // Update to transcribed status if needed
            if ($video->status !== 'transcribed') {
                $video->update(['status' => 'transcribed']);
                $this->info("Updated video status to 'transcribed'");
            }
            
            // Process terminology directly
            $controller = new \App\Http\Controllers\Api\TerminologyController();
            $request = new \Illuminate\Http\Request();
            
            $this->info("Calling terminology controller...");
            $response = $controller->process($request, $videoId);
            
            if ($response && $response->status() === 200) {
                $responseData = json_decode($response->getContent(), true);
                $this->info("Terminology processing started successfully");
                $this->info("Response: " . json_encode($responseData));
                return 0;
            } else {
                $this->error("Terminology processing failed");
                if ($response) {
                    $this->error("Response: " . $response->getContent());
                }
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("Error processing terminology: " . $e->getMessage());
            Log::error("Error processing terminology: " . $e->getMessage(), [
                'video_id' => $videoId,
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
} 