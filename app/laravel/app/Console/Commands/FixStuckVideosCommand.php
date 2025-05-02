<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Video;
use Illuminate\Support\Facades\Log;

class FixStuckVideosCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'videos:fix-stuck';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix videos that are stuck in the "transcribed" state but have terminology data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for videos stuck in "transcribed" state...');
        
        // Find videos that are in transcribed state but have terminology data
        $videos = Video::where('status', 'transcribed')
            ->where(function($query) {
                $query->whereNotNull('terminology_path')
                      ->orWhereNotNull('music_terms_path')
                      ->orWhere('has_terminology', true);
            })
            ->get();
        
        $count = 0;
        
        foreach ($videos as $video) {
            $this->info("Fixing video {$video->id} - {$video->original_filename}");
            
            try {
                // Update to completed status
                $video->update([
                    'status' => 'completed'
                ]);
                
                $count++;
                
                Log::info('Fixed stuck video via command', [
                    'video_id' => $video->id,
                    'old_status' => 'transcribed',
                    'new_status' => 'completed'
                ]);
            } catch (\Exception $e) {
                $this->error("Error updating video {$video->id}: " . $e->getMessage());
                
                Log::error('Error fixing stuck video', [
                    'video_id' => $video->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Check for videos that have been in "processing_music_terms" state for too long
        $threshold = now()->subMinutes(15); // 15 minutes threshold
        
        $stuckProcessingVideos = Video::where('status', 'processing_music_terms')
            ->where('updated_at', '<', $threshold)
            ->get();
        
        foreach ($stuckProcessingVideos as $video) {
            $this->info("Fixing stuck processing video {$video->id} - {$video->original_filename}");
            
            try {
                // Update to completed status
                $video->update([
                    'status' => 'completed'
                ]);
                
                $count++;
                
                Log::info('Fixed stuck processing video via command', [
                    'video_id' => $video->id,
                    'old_status' => 'processing_music_terms',
                    'new_status' => 'completed',
                    'stuck_for' => $video->updated_at->diffForHumans()
                ]);
            } catch (\Exception $e) {
                $this->error("Error updating video {$video->id}: " . $e->getMessage());
                
                Log::error('Error fixing stuck processing video', [
                    'video_id' => $video->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->info("Fixed {$count} videos");
        
        return Command::SUCCESS;
    }
} 