<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SegmentDownload;
use Carbon\Carbon;

class CleanupStaleSegmentDownloads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'segment-downloads:cleanup 
                            {--hours=2 : Number of hours to consider a processing record stale}
                            {--dry-run : Show what would be cleaned up without actually doing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up stale segment download processing records';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $dryRun = $this->option('dry-run');

        $this->info("Looking for stale processing records older than {$hours} hours...");

        // Find stale processing records
        $staleRecords = SegmentDownload::staleProcessing($hours)->get();

        if ($staleRecords->isEmpty()) {
            $this->info('No stale processing records found.');
            return self::SUCCESS;
        }

        $this->info("Found {$staleRecords->count()} stale processing records:");

        // Display the records in a table
        $tableData = $staleRecords->map(function ($record) {
            return [
                'ID' => $record->id,
                'Segment ID' => $record->segment_id,
                'Course ID' => $record->course_id ?? 'N/A',
                'Status' => $record->status,
                'Started At' => $record->started_at?->format('Y-m-d H:i:s') ?? 'N/A',
                'Hours Ago' => $record->started_at ? 
                    Carbon::now()->diffInHours($record->started_at) : 'N/A',
                'Attempts' => $record->attempts,
            ];
        })->toArray();

        $this->table([
            'ID', 'Segment ID', 'Course ID', 'Status', 'Started At', 'Hours Ago', 'Attempts'
        ], $tableData);

        if ($dryRun) {
            $this->warn('DRY RUN: No records were actually deleted.');
            return self::SUCCESS;
        }

        if (!$this->confirm('Do you want to delete these stale processing records?')) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        // Delete the stale records
        $deletedCount = SegmentDownload::staleProcessing($hours)->delete();

        $this->info("Successfully deleted {$deletedCount} stale processing records.");

        // Log the cleanup operation
        \Log::info('Cleaned up stale segment download processing records', [
            'deleted_count' => $deletedCount,
            'hours_threshold' => $hours,
            'command_run_at' => Carbon::now()->toDateTimeString(),
        ]);

        return self::SUCCESS;
    }
}