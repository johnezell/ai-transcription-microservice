<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Video;
use Illuminate\Support\Facades\DB;

class TranscriptionStatus extends Command
{
    protected $signature = 'truefire:status 
                            {course_id? : Filter by TrueFire course ID}
                            {--pending : Show only pending jobs}
                            {--failed : Show only failed jobs}
                            {--completed : Show only completed jobs}';
                            
    protected $description = 'Check status of transcription jobs';

    public function handle()
    {
        $courseId = $this->argument('course_id');
        
        $query = Video::query();

        if ($courseId) {
            // Handle both MySQL JSON path and SQLite json_extract
            $query->where(function ($q) use ($courseId) {
                $q->whereRaw("json_extract(metadata, '$.truefire_course_id') = ?", [(int) $courseId]);
            });
        }

        if ($this->option('pending')) {
            $query->whereIn('status', ['pending', 'queued', 'processing', 'transcribing']);
        } elseif ($this->option('failed')) {
            $query->where('status', 'failed');
        } elseif ($this->option('completed')) {
            $query->where('status', 'completed');
        }

        $videos = $query->orderBy('created_at', 'desc')->get();

        if ($videos->isEmpty()) {
            $this->info("No videos found matching criteria.");
            return 0;
        }

        // Summary by status
        $statusCounts = $videos->groupBy('status')->map->count();
        
        $this->info("Status Summary:");
        $this->table(
            ['Status', 'Count'],
            $statusCounts->map(fn($count, $status) => [$status, $count])->values()->toArray()
        );

        $this->newLine();
        $this->info("Total: {$videos->count()} video(s)");

        // Show details for small result sets
        if ($videos->count() <= 20) {
            $this->newLine();
            $this->info("Details:");
            
            $tableData = $videos->map(function ($video) {
                $metadata = $video->metadata ?? [];
                return [
                    \Illuminate\Support\Str::limit($video->id, 8),
                    \Illuminate\Support\Str::limit($video->original_filename, 25),
                    $video->status,
                    $metadata['truefire_segment_id'] ?? 'N/A',
                    $metadata['truefire_course_title'] ?? 'N/A',
                    $video->created_at?->diffForHumans() ?? 'N/A',
                ];
            })->toArray();

            $this->table(
                ['ID', 'Filename', 'Status', 'Segment ID', 'Course', 'Created'],
                $tableData
            );
        }

        // Queue check
        $queuedCount = $videos->where('status', 'queued')->count();
        if ($queuedCount > 0) {
            $this->newLine();
            $this->warn("{$queuedCount} job(s) are queued and waiting for ECS workers.");
            $this->line("Check SQS: aws sqs get-queue-attributes --queue-url " . config('queue.connections.sqs.prefix') . "/" . config('queue.connections.sqs.queue') . " --attribute-names ApproximateNumberOfMessages");
        }

        return 0;
    }
}

