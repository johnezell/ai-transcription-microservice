<?php

namespace App\Jobs;

use App\Models\LocalTruefireCourse;
use App\Models\TranscriptionLog;
use App\Jobs\AudioExtractionTestJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessCourseAudioExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The TrueFire course to process.
     *
     * @var LocalTruefireCourse
     */
    protected LocalTruefireCourse $course;

    /**
     * Whether this is for transcription workflow (MP3 output) or testing (WAV output).
     *
     * @var bool
     */
    protected bool $forTranscription;

    /**
     * Additional processing settings.
     *
     * @var array
     */
    protected array $settings;

    /**
     * Create a new job instance.
     *
     * @param LocalTruefireCourse $course
     * @param bool $forTranscription
     * @param array $settings
     */
    public function __construct(LocalTruefireCourse $course, bool $forTranscription = true, array $settings = [])
    {
        $this->course = $course;
        $this->forTranscription = $forTranscription;
        $this->settings = $settings;

        Log::info('ProcessCourseAudioExtractionJob created', [
            'course_id' => $course->id,
            'course_title' => $course->title ?? "Course #{$course->id}",
            'for_transcription' => $forTranscription,
            'preset' => $course->getAudioExtractionPreset(),
            'settings' => $settings,
            'workflow_step' => 'course_job_creation'
        ]);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        Log::info('ProcessCourseAudioExtractionJob started processing', [
            'course_id' => $this->course->id,
            'course_title' => $this->course->title ?? "Course #{$this->course->id}",
            'for_transcription' => $this->forTranscription,
            'preset' => $this->course->getAudioExtractionPreset(),
            'workflow_step' => 'course_job_processing_start'
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
                Log::warning('No segments found for course audio extraction', [
                    'course_id' => $this->course->id,
                    'workflow_step' => 'no_segments_found'
                ]);
                return;
            }

            $courseDir = "truefire-courses/{$this->course->id}";
            $disk = config('filesystems.default');
            $processedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;

            // Create a master transcription log for tracking course-level progress
            $masterLog = TranscriptionLog::create([
                'job_id' => 'course_audio_extract_' . $this->course->id . '_' . time() . '_' . uniqid(),
                'file_name' => "Course #{$this->course->id} Batch Processing",
                'file_path' => $courseDir,
                'file_size' => 0, // Will be updated with total size
                'status' => 'processing',
                'started_at' => now(),
                'is_test_extraction' => !$this->forTranscription,
                'test_quality_level' => $this->course->getAudioExtractionPreset(),
                'extraction_settings' => array_merge($this->settings, [
                    'course_id' => $this->course->id,
                    'total_segments' => $allSegments->count(),
                    'for_transcription' => $this->forTranscription,
                    'batch_processing' => true,
                    'initiated_at' => now()->toISOString()
                ])
            ]);

            Log::info('Course-level transcription log created', [
                'course_id' => $this->course->id,
                'master_log_id' => $masterLog->id,
                'total_segments' => $allSegments->count(),
                'workflow_step' => 'master_log_created'
            ]);

            // Process each segment
            foreach ($allSegments as $segment) {
                try {
                    // Check if video file exists locally
                    $videoFilename = "{$segment->id}.mp4";
                    $videoFilePath = "{$courseDir}/{$videoFilename}";

                    if (!Storage::disk($disk)->exists($videoFilePath)) {
                        Log::warning('Video file not found for segment in course processing', [
                            'course_id' => $this->course->id,
                            'segment_id' => $segment->id,
                            'video_file_path' => $videoFilePath,
                            'workflow_step' => 'segment_file_not_found'
                        ]);
                        $skippedCount++;
                        continue;
                    }

                    // Prepare extraction settings based on transcription vs testing mode
                    $extractionSettings = array_merge($this->settings, [
                        'course_batch_processing' => true,
                        'master_log_id' => $masterLog->id,
                        'for_transcription' => $this->forTranscription,
                        'output_format' => $this->forTranscription ? 'mp3' : 'wav',
                        'simple_naming' => $this->forTranscription, // Use simple naming for transcription
                        'sample_rate' => $this->forTranscription ? 16000 : 44100,
                        'channels' => $this->forTranscription ? 1 : 2,
                        'bit_rate' => $this->forTranscription ? '128k' : '192k'
                    ]);

                    // Dispatch individual audio extraction job
                    AudioExtractionTestJob::dispatch(
                        $videoFilePath,
                        $videoFilename,
                        $this->course->getAudioExtractionPreset(),
                        $extractionSettings,
                        $segment->id,
                        $this->course->id
                    );

                    $processedCount++;

                    Log::info('Dispatched audio extraction job for course segment', [
                        'course_id' => $this->course->id,
                        'segment_id' => $segment->id,
                        'video_file_path' => $videoFilePath,
                        'quality_level' => $this->course->getAudioExtractionPreset(),
                        'for_transcription' => $this->forTranscription,
                        'workflow_step' => 'segment_job_dispatched'
                    ]);

                } catch (\Exception $e) {
                    Log::error('Error processing segment in course audio extraction', [
                        'course_id' => $this->course->id,
                        'segment_id' => $segment->id,
                        'error' => $e->getMessage(),
                        'workflow_step' => 'segment_processing_error'
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

            Log::info('ProcessCourseAudioExtractionJob completed', [
                'course_id' => $this->course->id,
                'master_log_id' => $masterLog->id,
                'processed_segments' => $processedCount,
                'skipped_segments' => $skippedCount,
                'error_segments' => $errorCount,
                'total_segments' => $allSegments->count(),
                'for_transcription' => $this->forTranscription,
                'preset' => $this->course->getAudioExtractionPreset(),
                'workflow_step' => 'course_job_completed'
            ]);

        } catch (\Exception $e) {
            Log::error('Critical error in ProcessCourseAudioExtractionJob', [
                'course_id' => $this->course->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'workflow_step' => 'course_job_critical_error'
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
     * @return TruefireCourse
     */
    public function getCourse(): TruefireCourse
    {
        return $this->course;
    }

    /**
     * Check if this job is for transcription workflow.
     *
     * @return bool
     */
    public function isForTranscription(): bool
    {
        return $this->forTranscription;
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

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array
     */
    public function tags(): array
    {
        $tags = [
            'course-audio-extraction',
            'course:' . $this->course->id,
            'preset:' . $this->course->getAudioExtractionPreset()
        ];

        if ($this->forTranscription) {
            $tags[] = 'transcription-workflow';
            $tags[] = 'mp3-output';
        } else {
            $tags[] = 'testing-workflow';
            $tags[] = 'wav-output';
        }

        return $tags;
    }
}