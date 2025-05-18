<?php

namespace App\Console\Commands;

use App\Jobs\ThumbnailExtractionJob;
use App\Models\Video;
use Illuminate\Console\Command;

class GenerateThumbnails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'videos:generate-thumbnails {--force : Force regeneration of thumbnails}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate thumbnails for all videos that don\'t have one';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $force = $this->option('force');
        
        $query = Video::query();
        
        if (!$force) {
            $query->whereNull('thumbnail_path');
        }
        
        $videosCount = $query->count();
        
        if ($videosCount === 0) {
            $this->info('No videos found that need thumbnails.');
            return 0;
        }
        
        $this->info("Found {$videosCount} videos that need thumbnails. Processing...");
        
        $progressBar = $this->output->createProgressBar($videosCount);
        $progressBar->start();
        
        $videos = $query->get();
        
        foreach ($videos as $video) {
            $videoKey = $video->s3_key;
            $outputKey = "jobs/{$video->id}/thumbnail.jpg";
            
            ThumbnailExtractionJob::dispatch($video, $videoKey, $outputKey);
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine();
        $this->info('Thumbnail generation has been queued for all videos.');
        
        return 0;
    }
} 