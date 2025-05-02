<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Models\Video;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Run every 10 minutes to fix videos stuck in transcribed state
        $schedule->call(function () {
            $stuckVideos = Video::where('status', 'transcribed')
                ->where(function ($query) {
                    $query->where('has_terminology', true)
                          ->orWhereNotNull('terminology_path');
                })
                ->get();
            
            if ($stuckVideos->isNotEmpty()) {
                Log::info('Found videos stuck in transcribed state with terminology data', [
                    'count' => $stuckVideos->count(),
                    'video_ids' => $stuckVideos->pluck('id')->toArray()
                ]);
                
                foreach ($stuckVideos as $video) {
                    $video->update(['status' => 'completed']);
                    Log::info('Automatically fixed video stuck in transcribed state', [
                        'video_id' => $video->id,
                        'previous_status' => 'transcribed',
                        'new_status' => 'completed'
                    ]);
                }
            }
        })->everyTenMinutes();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
} 