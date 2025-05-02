<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Video;
use Illuminate\Support\Facades\Log;

class FixStuckVideos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'videos:fix-stuck {--force : Force fixing without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix videos stuck in transcribed state with terminology data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Searching for videos stuck in transcribed state with terminology data...');
        
        $stuckVideos = Video::where('status', 'transcribed')
            ->where(function ($query) {
                $query->where('has_terminology', true)
                      ->orWhereNotNull('terminology_path');
            })
            ->get();
        
        if ($stuckVideos->isEmpty()) {
            $this->info('No stuck videos found!');
            return 0;
        }
        
        $this->info('Found ' . $stuckVideos->count() . ' stuck videos:');
        
        $table = [];
        foreach ($stuckVideos as $video) {
            $table[] = [
                'id' => $video->id,
                'original_filename' => $video->original_filename,
                'status' => $video->status,
                'has_terminology' => $video->has_terminology ? 'Yes' : 'No',
                'terminology_path' => $video->terminology_path ?? 'None',
            ];
        }
        
        $this->table(
            ['ID', 'Filename', 'Status', 'Has Terminology', 'Terminology Path'],
            $table
        );
        
        if ($this->option('force') || $this->confirm('Do you want to fix these videos?')) {
            $count = 0;
            foreach ($stuckVideos as $video) {
                $video->update(['status' => 'completed']);
                $count++;
                
                Log::info('Manually fixed video stuck in transcribed state', [
                    'video_id' => $video->id,
                    'previous_status' => 'transcribed',
                    'new_status' => 'completed'
                ]);
            }
            
            $this->info('Successfully fixed ' . $count . ' videos!');
        }
        
        return 0;
    }
} 