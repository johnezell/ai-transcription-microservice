<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TrueFire\TrueFireCourse;
use App\Models\TrueFire\TrueFireSegment;
use App\Models\Video;
use App\Jobs\TrueFireTranscriptionJob;
use Illuminate\Support\Facades\DB;
use Aws\S3\S3Client;

class TranscribeTrueFireCourse extends Command
{
    protected $signature = 'truefire:transcribe-course 
                            {course_id : The TrueFire course ID to transcribe}
                            {--quality=hi : Video quality (low, med, hi)}
                            {--dry-run : Show what would be done without processing}
                            {--dispatch : Dispatch jobs to SQS for ECS processing}
                            {--force : Re-queue even if already processed}
                            {--limit= : Limit number of segments to process}';
                            
    protected $description = 'Queue all lessons from a TrueFire course for transcription';

    private S3Client $s3Client;
    
    // Track stats
    private int $created = 0;
    private int $skipped = 0;
    private int $queued = 0;
    private int $failed = 0;

    public function handle()
    {
        $courseId = $this->argument('course_id');
        $quality = $this->option('quality');
        $dryRun = $this->option('dry-run');
        $dispatch = $this->option('dispatch');
        $force = $this->option('force');
        $limit = $this->option('limit');

        $this->info("Fetching course ID: {$courseId}");
        $this->newLine();

        // Fetch course
        $course = TrueFireCourse::find($courseId);
        if (!$course) {
            $this->error("Course {$courseId} not found!");
            return 1;
        }

        $this->displayCourseInfo($course);

        // Fetch all segments for this course
        $segments = $this->getSegmentsForCourse($courseId, $limit);
        
        if ($segments->isEmpty()) {
            $this->warn("No segments found for course {$courseId}");
            return 0;
        }

        $this->info("Found {$segments->count()} segment(s) to process");
        $this->newLine();

        // Show queue configuration
        $this->showQueueConfig();

        if ($dryRun) {
            $this->newLine();
            $this->warn("DRY RUN - No actual processing will occur.");
            $this->showDryRunSummary($segments, $course, $quality, $force);
            return 0;
        }

        // Process each segment
        $this->newLine();
        $this->info("Processing segments...");
        $this->line(str_repeat('-', 60));

        $progressBar = $this->output->createProgressBar($segments->count());
        $progressBar->start();

        foreach ($segments as $segment) {
            $result = $this->processSegment($segment, $course, $quality, $dispatch, $force);
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Show summary
        $this->showProcessingSummary($dispatch);

        return 0;
    }

    private function displayCourseInfo($course): void
    {
        $this->info("Course Details:");
        $this->line(str_repeat('-', 50));
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $course->id],
                ['Title', $course->title ?? 'N/A'],
                ['Status', $course->status ?? 'N/A'],
                ['Permalink', $course->perma_link ?? 'N/A'],
            ]
        );
    }

    private function getSegmentsForCourse(int $courseId, ?int $limit = null)
    {
        $query = DB::connection('truefire')
            ->table('channels.channels as ch')
            ->join('channels.segments as s', 's.channel_id', '=', 'ch.id')
            ->where('ch.courseid', $courseId)
            ->whereNotNull('s.video')
            ->where('s.video', '!=', '')
            ->select('s.*')
            ->orderBy('s.id');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    private function processSegment($segment, $course, string $quality, bool $dispatch, bool $force): string
    {
        $segmentModel = new TrueFireSegment((array) $segment);
        $s3Key = $segmentModel->getS3Key("_{$quality}");

        // Check if already processed (query RDS via ECS, but we can check local cache or skip)
        // For now, we'll dispatch and let ECS handle deduplication via updateOrCreate
        
        try {
            $fileSize = $this->getS3FileSize($s3Key);
            
            if ($fileSize === 0) {
                $this->failed++;
                return 'failed';
            }

            $this->created++;

            if ($dispatch) {
                // Dispatch job with segment data (not a model)
                $jobData = [
                    'original_filename' => $segment->name ?? "Segment {$segment->id}",
                    's3_key' => $s3Key,
                    'mime_type' => 'video/mp4',
                    'size_bytes' => $fileSize,
                    'metadata' => [
                        'truefire_segment_id' => $segment->id,
                        'truefire_channel_id' => $segment->channel_id,
                        'truefire_course_id' => $course->id,
                        'truefire_course_title' => $course->title,
                        's3_bucket' => TrueFireSegment::S3_BUCKET,
                        'cloudfront_url' => $segmentModel->getCloudFrontUrl(),
                        'quality' => $quality,
                    ],
                ];

                TrueFireTranscriptionJob::dispatch($jobData);
                $this->queued++;
            }

            return 'created';
        } catch (\Exception $e) {
            $this->failed++;
            return 'failed';
        }
    }

    private function createOrUpdateVideo($segment, TrueFireSegment $model, $course, string $s3Key, int $fileSize, ?Video $existing): Video
    {
        $data = [
            'original_filename' => $segment->name ?? "Segment {$segment->id}",
            's3_key' => $s3Key,
            'mime_type' => 'video/mp4',
            'size_bytes' => $fileSize,
            'status' => 'pending',
            'metadata' => [
                'truefire_segment_id' => $segment->id,
                'truefire_channel_id' => $segment->channel_id,
                'truefire_course_id' => $course->id,
                'truefire_course_title' => $course->title,
                's3_bucket' => TrueFireSegment::S3_BUCKET,
                'cloudfront_url' => $model->getCloudFrontUrl(),
            ],
        ];

        if ($existing) {
            $existing->update($data);
            return $existing;
        }

        return Video::create($data);
    }

    private function showQueueConfig(): void
    {
        $queueConnection = config('queue.default');
        $queueName = config("queue.connections.{$queueConnection}.queue");

        $this->info("Queue Configuration:");
        $this->table(
            ['Setting', 'Value'],
            [
                ['Queue Driver', $queueConnection],
                ['Queue Name', $queueName],
                ['SQS Prefix', $queueConnection === 'sqs' ? config('queue.connections.sqs.prefix') : 'N/A'],
            ]
        );
    }

    private function showDryRunSummary($segments, $course, string $quality, bool $force): void
    {
        $this->newLine();
        
        $this->info("Would dispatch {$segments->count()} jobs to SQS:");
        $this->line("  • ECS workers will create/update Video records in RDS");
        $this->line("  • Deduplication handled via s3_key uniqueness");

        $this->newLine();
        $this->info("Sample segments:");
        
        $sample = $segments->take(5);
        $tableData = [];
        foreach ($sample as $segment) {
            $segmentModel = new TrueFireSegment((array) $segment);
            
            $tableData[] = [
                $segment->id,
                \Illuminate\Support\Str::limit($segment->name ?? 'N/A', 30),
                $segmentModel->getS3Key("_{$quality}"),
            ];
        }

        $this->table(
            ['Segment ID', 'Name', 'S3 Key'],
            $tableData
        );

        if ($segments->count() > 5) {
            $this->line("... and " . ($segments->count() - 5) . " more segments");
        }
    }

    private function showProcessingSummary(bool $dispatch): void
    {
        $this->info("Processing Summary:");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $this->created],
                ['Queued to SQS', $this->queued],
                ['Failed (S3 not found)', $this->failed],
            ]
        );

        if ($dispatch && $this->queued > 0) {
            $this->newLine();
            $this->info("✓ {$this->queued} job(s) dispatched to SQS");
            $this->newLine();
            $this->line("ECS workers will:");
            $this->line("  1. Create/update Video records in RDS");
            $this->line("  2. Download video from S3 (tfstream bucket)");
            $this->line("  3. Extract audio → Transcribe → Save results");
        } elseif (!$dispatch && $this->created > 0) {
            $this->newLine();
            $this->warn("Jobs NOT dispatched. Run with --dispatch to queue for ECS.");
        }

        $this->newLine();
        $this->info("Deduplication:");
        $this->line("  • ECS uses updateOrCreate on s3_key");
        $this->line("  • Safe to re-run - existing records will be updated");
    }

    private function getS3FileSize(string $key): int
    {
        try {
            $client = $this->getS3Client();
            $result = $client->headObject([
                'Bucket' => TrueFireSegment::S3_BUCKET,
                'Key' => $key,
            ]);
            return $result['ContentLength'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getS3Client(): S3Client
    {
        if (!isset($this->s3Client)) {
            $config = [
                'version' => 'latest',
                'region' => 'us-east-1',
            ];
            
            // Use profile locally, ECS task role in production
            $profile = env('TRUEFIRE_AWS_PROFILE');
            if ($profile) {
                $config['profile'] = $profile;
            }
            
            $this->s3Client = new S3Client($config);
        }
        return $this->s3Client;
    }
}

