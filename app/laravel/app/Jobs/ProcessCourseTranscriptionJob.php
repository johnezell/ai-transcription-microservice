<?php

namespace App\Jobs;

use App\Models\LocalTruefireCourse;
use App\Models\TranscriptionLog;
use App\Jobs\TranscriptionTestJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessCourseTranscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The TrueFire course to process.
     *
     * @var LocalTruefireCourse
     */
    protected LocalTruefireCourse $course;

    /**
     * The transcription preset to use.
     *
     * @var string
     */
    protected string $preset;

    /**
     * Additional processing settings.
     *
     * @var array
     */
    protected array $settings;

    /**
     * Whether to force restart existing transcriptions.
     *
     * @var bool
     */
    protected bool $forceRestart;

    /**
     * Create a new job instance.
     *
     * @param LocalTruefireCourse $course
     * @param string $preset
     * @param array $settings
     * @param bool $forceRestart
     */
    public function __construct(LocalTruefireCourse $course, string $preset = 'balanced', array $settings = [], bool $forceRestart = false)
    {
        $this->course = $course;
        $this->preset = $preset;
        $this->settings = $settings;
        $this->forceRestart = $forceRestart;

        Log::info('ProcessCourseTranscriptionJob created', [
            'course_id' => $course->id,
            'course_title' => $course->title ?? "Course #{$course->id}",
            'preset' => $preset,
            'force_restart' => $forceRestart,
            'settings' => $settings,
            'workflow_step' => 'course_transcription_job_creation'
        ]);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        Log::info('ProcessCourseTranscriptionJob started processing', [
            'course_id' => $this->course->id,
            'course_title' => $this->course->title ?? "Course #{$this->course->id}",
            'preset' => $this->preset,
            'force_restart' => $this->forceRestart,
            'workflow_step' => 'course_transcription_job_processing_start'
        ]);

        try {
            // Load course with segments
            $course = $this->course->load(['channels.segments' => function ($query) {
                $query->withVideo(); // Only load segments with valid video fields
            }]);

            // Collect all segments from channels
            $allSegments = collect();
            foreach ($course->channels as $channel) {
                $allSegments = $allSegments->merge($channel->segments);
            }

            if ($allSegments->isEmpty()) {
                Log::warning('No segments found for course transcription', [
                    'course_id' => $this->course->id,
                    'workflow_step' => 'no_segments_found'
                ]);
                return;
            }

            $courseDir = "truefire-courses/{$this->course->id}";
            $disk = 'd_drive'; // Always use d_drive for TrueFire courses
            $processedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;

            // Create a master transcription log for tracking course-level progress
            $masterLog = TranscriptionLog::create([
                'job_id' => 'course_transcription_' . $this->course->id . '_' . time() . '_' . uniqid(),
                'file_name' => "Course #{$this->course->id} Transcription Batch",
                'file_path' => $courseDir,
                'file_size' => 0, // Will be updated with total size
                'status' => 'processing',
                'started_at' => now(),
                'is_test_extraction' => false, // This is transcription, not audio extraction
                'test_quality_level' => null,
                'extraction_settings' => array_merge($this->settings, [
                    'course_id' => $this->course->id,
                    'total_segments' => $allSegments->count(),
                    'preset' => $this->preset,
                    'force_restart' => $this->forceRestart,
                    'batch_processing' => true,
                    'processing_type' => 'transcription',
                    'initiated_at' => now()->toISOString()
                ])
            ]);

            Log::info('Course-level transcription log created', [
                'course_id' => $this->course->id,
                'master_log_id' => $masterLog->id,
                'total_segments' => $allSegments->count(),
                'preset' => $this->preset,
                'workflow_step' => 'master_transcription_log_created'
            ]);

            // Process each segment
            foreach ($allSegments as $segment) {
                try {
                    // Check if audio file exists locally
                    $audioFilename = "{$segment->id}.wav";
                    $audioFilePath = "{$courseDir}/{$audioFilename}";

                    if (!Storage::disk($disk)->exists($audioFilePath)) {
                        Log::warning('Audio file not found for segment in course transcription', [
                            'course_id' => $this->course->id,
                            'segment_id' => $segment->id,
                            'audio_file_path' => $audioFilePath,
                            'disk' => $disk,
                            'full_path_attempted' => Storage::disk($disk)->path($audioFilePath),
                            'workflow_step' => 'segment_audio_not_found'
                        ]);
                        $skippedCount++;
                        continue;
                    }

                    // Check if transcription already exists and not forcing restart
                    $transcriptPath = "{$courseDir}/{$segment->id}_transcript.txt";
                    if (!$this->forceRestart && Storage::disk($disk)->exists($transcriptPath)) {
                        Log::info('Transcription already exists for segment, skipping', [
                            'course_id' => $this->course->id,
                            'segment_id' => $segment->id,
                            'transcript_path' => $transcriptPath,
                            'workflow_step' => 'segment_transcription_exists'
                        ]);
                        $skippedCount++;
                        continue;
                    }

                    // Prepare transcription settings
                    $transcriptionSettings = array_merge($this->settings, [
                        'course_batch_processing' => true,
                        'master_log_id' => $masterLog->id,
                        'preset' => $this->preset,
                        'force_restart' => $this->forceRestart,
                        'course_id' => $this->course->id,
                        'segment_id' => $segment->id
                    ]);

                    // Generate unique test ID for transcription job
                    $testId = 'course_transcription_' . $this->course->id . '_' . $segment->id . '_' . time() . '_' . uniqid();

                    // Dispatch individual transcription job
                    TranscriptionTestJob::dispatch(
                        $audioFilePath,
                        $audioFilename,
                        $this->preset,
                        $transcriptionSettings,
                        $segment->id,
                        $this->course->id,
                        $testId
                    );

                    $processedCount++;

                    Log::info('Dispatched transcription job for course segment', [
                        'course_id' => $this->course->id,
                        'segment_id' => $segment->id,
                        'audio_file_path' => $audioFilePath,
                        'preset' => $this->preset,
                        'test_id' => $testId,
                        'force_restart' => $this->forceRestart,
                        'workflow_step' => 'segment_transcription_job_dispatched'
                    ]);

                } catch (\Exception $e) {
                    Log::error('Error processing segment in course transcription', [
                        'course_id' => $this->course->id,
                        'segment_id' => $segment->id,
                        'error' => $e->getMessage(),
                        'workflow_step' => 'segment_transcription_processing_error'
                    ]);
                    $errorCount++;
                }
            }

            // Update master log with final statistics
            $masterLog->update([
                'extraction_settings' => array_merge($masterLog->extraction_settings, [
                    'processed_segments' => $processedCount,
                    'skipped_segments' => $skippedCount,
                    'error_segments' => $errorCount,
                    'completion_time' => now()->toISOString()
                ]),
                'progress_percentage' => 100,
                'status' => $errorCount > 0 ? 'completed_with_errors' : 'completed',
                'completed_at' => now(),
                'total_processing_duration_seconds' => now()->diffInSeconds($masterLog->started_at)
            ]);

            Log::info('ProcessCourseTranscriptionJob completed', [
                'course_id' => $this->course->id,
                'master_log_id' => $masterLog->id,
                'processed_segments' => $processedCount,
                'skipped_segments' => $skippedCount,
                'error_segments' => $errorCount,
                'total_segments' => $allSegments->count(),
                'preset' => $this->preset,
                'force_restart' => $this->forceRestart,
                'workflow_step' => 'course_transcription_job_completed'
            ]);

        } catch (\Exception $e) {
            Log::error('Critical error in ProcessCourseTranscriptionJob', [
                'course_id' => $this->course->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'workflow_step' => 'course_transcription_job_critical_error'
            ]);

            // Update master log if it exists
            if (isset($masterLog)) {
                $masterLog->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => now(),
                    'total_processing_duration_seconds' => now()->diffInSeconds($masterLog->started_at)
                ]);
            }

            throw $e;
        }
    }

    /**
     * Get the course being processed.
     *
     * @return LocalTruefireCourse
     */
    public function getCourse(): LocalTruefireCourse
    {
        return $this->course;
    }

    /**
     * Get the transcription preset being used.
     *
     * @return string
     */
    public function getPreset(): string
    {
        return $this->preset;
    }

    /**
     * Check if this job is forcing restart.
     *
     * @return bool
     */
    public function isForceRestart(): bool
    {
        return $this->forceRestart;
    }

    /**
     * Get the processing settings.
     *
     * @return array
     */
    public function getSettings(): array
    {
        return $this->settings;
    }
} 