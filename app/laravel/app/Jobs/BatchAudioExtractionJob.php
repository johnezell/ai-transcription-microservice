<?php

namespace App\Jobs;

use App\Models\AudioTestBatch;
use App\Models\TranscriptionLog;
use App\Models\Video;
use App\Models\Segment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Bus\Batch;
use Throwable;

class BatchAudioExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The audio test batch instance.
     *
     * @var \App\Models\AudioTestBatch
     */
    protected $batch;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 3600; // 1 hour

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\AudioTestBatch  $batch
     * @return void
     */
    public function __construct(AudioTestBatch $batch)
    {
        $this->batch = $batch;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            Log::info('Starting batch audio extraction processing', [
                'batch_id' => $this->batch->id,
                'batch_name' => $this->batch->name,
                'total_segments' => $this->batch->total_segments,
                'concurrent_jobs' => $this->batch->concurrent_jobs,
                'quality_level' => $this->batch->quality_level
            ]);

            // Mark batch as started
            $this->batch->markAsStarted();

            // Get segments to process
            $segmentIds = $this->batch->segment_ids;
            $segments = Segment::whereIn('id', $segmentIds)->get();

            if ($segments->isEmpty()) {
                Log::error('No segments found for batch processing', [
                    'batch_id' => $this->batch->id,
                    'segment_ids' => $segmentIds
                ]);
                $this->batch->markAsFailed();
                return;
            }

            // Create individual jobs for each segment
            $jobs = [];
            $position = 1;

            foreach ($segments as $segment) {
                // Find the video file for this segment
                $video = $this->findVideoForSegment($segment);
                
                if (!$video) {
                    Log::warning('No video found for segment in batch', [
                        'batch_id' => $this->batch->id,
                        'segment_id' => $segment->id
                    ]);
                    continue;
                }

                // Create transcription log for this batch item
                $transcriptionLog = $this->createBatchTranscriptionLog($video, $segment, $position);

                // Create the individual test job
                $job = new AudioExtractionTestJob(
                    $video,
                    $this->batch->quality_level,
                    $this->batch->extraction_settings ?? [],
                    $segment->id
                );

                // Set the batch context for the job
                $job->setBatchContext($this->batch, $transcriptionLog);

                $jobs[] = $job;
                $position++;
            }

            if (empty($jobs)) {
                Log::error('No valid jobs created for batch processing', [
                    'batch_id' => $this->batch->id
                ]);
                $this->batch->markAsFailed();
                return;
            }

            // Create Laravel batch for concurrent processing
            $batchName = "Audio Test Batch: {$this->batch->name}";
            
            $laravelBatch = Bus::batch($jobs)
                ->name($batchName)
                ->allowFailures()
                ->then(function (Batch $batch) {
                    $this->handleBatchCompletion($batch);
                })
                ->catch(function (Batch $batch, Throwable $e) {
                    $this->handleBatchFailure($batch, $e);
                })
                ->finally(function (Batch $batch) {
                    $this->handleBatchFinally($batch);
                })
                ->dispatch();

            // Update batch with Laravel batch ID
            $this->batch->update([
                'batch_job_id' => $laravelBatch->id
            ]);

            Log::info('Batch audio extraction jobs dispatched', [
                'batch_id' => $this->batch->id,
                'laravel_batch_id' => $laravelBatch->id,
                'job_count' => count($jobs)
            ]);

        } catch (\Exception $e) {
            Log::error('Exception in batch audio extraction job', [
                'batch_id' => $this->batch->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->batch->markAsFailed();
            throw $e;
        }
    }

    /**
     * Find the video file for a given segment.
     *
     * @param \App\Models\Segment $segment
     * @return \App\Models\Video|null
     */
    protected function findVideoForSegment(Segment $segment): ?Video
    {
        // Try to find video by segment relationship or course
        if ($segment->video_id) {
            return Video::find($segment->video_id);
        }

        // If segment belongs to a course, find the main video for that course
        if ($segment->course_id) {
            return Video::where('course_id', $segment->course_id)->first();
        }

        // Fallback: try to find by TrueFire course relationship
        if ($this->batch->truefire_course_id) {
            return Video::where('course_id', $this->batch->truefire_course_id)->first();
        }

        return null;
    }

    /**
     * Create a transcription log for batch processing.
     *
     * @param \App\Models\Video $video
     * @param \App\Models\Segment $segment
     * @param int $position
     * @return \App\Models\TranscriptionLog
     */
    protected function createBatchTranscriptionLog(Video $video, Segment $segment, int $position): TranscriptionLog
    {
        return TranscriptionLog::create([
            'job_id' => $video->id,
            'video_id' => $video->id,
            'status' => 'queued',
            'started_at' => now(),
            'is_test_extraction' => true,
            'test_quality_level' => $this->batch->quality_level,
            'extraction_settings' => array_merge($this->batch->extraction_settings ?? [], [
                'segment_id' => $segment->id,
                'batch_mode' => true,
                'batch_id' => $this->batch->id,
                'batch_position' => $position
            ]),
            'audio_test_batch_id' => $this->batch->id,
            'batch_position' => $position,
            'file_path' => $video->storage_path,
            'file_name' => basename($video->storage_path),
            'file_size' => Storage::disk('public')->exists($video->storage_path) 
                ? Storage::disk('public')->size($video->storage_path) 
                : 0,
        ]);
    }

    /**
     * Handle batch completion.
     *
     * @param \Illuminate\Bus\Batch $batch
     * @return void
     */
    protected function handleBatchCompletion(Batch $batch): void
    {
        Log::info('Batch audio extraction completed successfully', [
            'batch_id' => $this->batch->id,
            'laravel_batch_id' => $batch->id,
            'total_jobs' => $batch->totalJobs,
            'processed_jobs' => $batch->processedJobs(),
            'failed_jobs' => $batch->failedJobs
        ]);

        // Update progress and mark as completed
        $this->batch->updateProgress();
        
        if (!$this->batch->isCompleted()) {
            $this->batch->markAsCompleted();
        }
    }

    /**
     * Handle batch failure.
     *
     * @param \Illuminate\Bus\Batch $batch
     * @param \Throwable $e
     * @return void
     */
    protected function handleBatchFailure(Batch $batch, Throwable $e): void
    {
        Log::error('Batch audio extraction failed', [
            'batch_id' => $this->batch->id,
            'laravel_batch_id' => $batch->id,
            'error' => $e->getMessage(),
            'total_jobs' => $batch->totalJobs,
            'processed_jobs' => $batch->processedJobs(),
            'failed_jobs' => $batch->failedJobs
        ]);

        // Update progress and mark as failed if too many failures
        $this->batch->updateProgress();
        
        // Mark as failed if more than 50% of jobs failed
        $failureRate = $batch->failedJobs / $batch->totalJobs;
        if ($failureRate > 0.5) {
            $this->batch->markAsFailed();
        }
    }

    /**
     * Handle batch finally callback.
     *
     * @param \Illuminate\Bus\Batch $batch
     * @return void
     */
    protected function handleBatchFinally(Batch $batch): void
    {
        Log::info('Batch audio extraction finished', [
            'batch_id' => $this->batch->id,
            'laravel_batch_id' => $batch->id,
            'final_status' => $this->batch->fresh()->status,
            'total_jobs' => $batch->totalJobs,
            'processed_jobs' => $batch->processedJobs(),
            'failed_jobs' => $batch->failedJobs,
            'cancelled' => $batch->cancelled()
        ]);

        // Final progress update
        $this->batch->updateProgress();
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Batch audio extraction job failed', [
            'batch_id' => $this->batch->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $this->batch->markAsFailed();
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array
     */
    public function tags(): array
    {
        return [
            'batch-audio-extraction',
            'batch:' . $this->batch->id,
            'user:' . $this->batch->user_id,
            'quality:' . $this->batch->quality_level
        ];
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return int
     */
    public function backoff(): int
    {
        return 60; // Wait 1 minute before retry
    }
}
