<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TrueFire\TrueFireCourse;
use App\Models\TrueFire\TrueFireSegment;
use App\Models\Video;
use App\Models\TranscriptionLog;
use App\Jobs\AudioExtractionJob;
use Illuminate\Support\Facades\DB;
use Aws\S3\S3Client;

class TranscribeTrueFireSegment extends Command
{
    protected $signature = 'truefire:transcribe 
                            {segment_id : The segment ID to transcribe}
                            {--quality=hi : Video quality (low, med, hi)}
                            {--dry-run : Show what would be done without actually processing}
                            {--dispatch : Dispatch job to queue for ECS processing}
                            {--sync : Run job synchronously (local testing only)}';
                            
    protected $description = 'Queue a TrueFire segment video for transcription via ECS';

    private S3Client $s3Client;

    public function handle()
    {
        $segmentId = $this->argument('segment_id');
        $quality = $this->option('quality');
        $dryRun = $this->option('dry-run');
        $dispatch = $this->option('dispatch');
        $sync = $this->option('sync');

        $this->info("Fetching segment ID: {$segmentId}");
        $this->newLine();

        // Fetch segment from TrueFire database
        $segment = DB::connection('truefire')
            ->table('channels.segments')
            ->where('id', $segmentId)
            ->first();

        if (!$segment) {
            $this->error("Segment {$segmentId} not found!");
            return 1;
        }

        // Create segment model for helper methods
        $segmentModel = new TrueFireSegment((array) $segment);

        // Get channel and course info
        $channel = DB::connection('truefire')
            ->table('channels.channels')
            ->where('id', $segment->channel_id)
            ->first();

        $course = null;
        if ($channel) {
            $course = TrueFireCourse::find($channel->courseid);
        }

        $this->displaySegmentInfo($segment, $segmentModel, $channel, $course);

        if (empty($segment->video)) {
            $this->error("Segment has no video path!");
            return 1;
        }

        // Build S3 key for video
        $s3Key = $segmentModel->getS3Key("_{$quality}");

        $this->newLine();
        $this->info("Source Configuration:");
        $this->line(str_repeat('-', 50));
        $this->table(
            ['Property', 'Value'],
            [
                ['Quality', $quality],
                ['S3 Bucket', TrueFireSegment::S3_BUCKET],
                ['S3 Key', $s3Key],
                ['CloudFront URL', $segmentModel->getCloudFrontUrl("_{$quality}")],
            ]
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn("DRY RUN - No actual processing will occur.");
            $this->showDryRunPlan($segment, $segmentModel, $course, $s3Key);
            return 0;
        }

        // Verify file exists in S3
        $this->newLine();
        $this->info("Verifying S3 file exists...");
        
        if (!$this->verifyS3FileExists($s3Key)) {
            $this->error("File not found in S3: {$s3Key}");
            return 1;
        }
        $this->info("✓ File verified in S3");

        // Get file size
        $fileSize = $this->getS3FileSize($s3Key);
        $this->line("  Size: " . $this->formatBytes($fileSize));

        // Create Video record
        $this->newLine();
        $this->info("Creating database record...");
        
        $video = $this->createVideoRecord($segment, $segmentModel, $course, $s3Key, $fileSize);
        $this->info("✓ Video record created: {$video->id}");

        // Show summary
        $this->newLine();
        $this->showVideoSummary($video);

        if ($sync) {
            // Run synchronously for local testing (requires local services)
            $this->newLine();
            $this->warn("Running job SYNCHRONOUSLY (local testing mode)...");
            $this->warn("Note: This requires audio-extraction and transcription services running locally.");
            
            $video->update(['status' => 'processing']);
            
            try {
                AudioExtractionJob::dispatchSync($video);
                $this->info("✓ Job completed synchronously!");
            } catch (\Exception $e) {
                $this->error("Job failed: " . $e->getMessage());
                $video->update(['status' => 'failed']);
                return 1;
            }
        } elseif ($dispatch) {
            $this->newLine();
            
            $queueConnection = config('queue.default');
            $queueName = config("queue.connections.{$queueConnection}.queue");
            
            $this->info("Dispatching job to queue...");
            $this->table(
                ['Setting', 'Value'],
                [
                    ['Queue Driver', $queueConnection],
                    ['Queue Name', $queueName],
                    ['SQS Prefix', $queueConnection === 'sqs' ? config('queue.connections.sqs.prefix') : 'N/A'],
                ]
            );
            
            AudioExtractionJob::dispatch($video);
            
            $video->update(['status' => 'queued']);
            
            $this->newLine();
            $this->info("✓ Job dispatched! Video status: queued");
            $this->newLine();
            $this->line("ECS workers will:");
            $this->line("  1. Download video from S3: s3://" . TrueFireSegment::S3_BUCKET . "/{$s3Key}");
            $this->line("  2. Extract audio to WAV (16kHz mono)");
            $this->line("  3. Run Whisper transcription on GPU");
            $this->line("  4. Save transcript.txt, transcript.srt, transcript.json");
            $this->line("  5. Update video record with transcript");
        } else {
            $this->newLine();
            $this->warn("Job NOT dispatched. Options:");
            $this->line("  --dispatch  Queue job for ECS processing (production)");
            $this->line("  --sync      Run synchronously (local testing)");
            $this->newLine();
            $this->line("Example: php artisan truefire:transcribe {$segmentId} --dispatch");
        }

        return 0;
    }

    private function displaySegmentInfo($segment, TrueFireSegment $model, $channel, $course): void
    {
        $this->info("Segment Details:");
        $this->line(str_repeat('-', 50));
        $this->table(
            ['Field', 'Value'],
            [
                ['Segment ID', $segment->id],
                ['Name', $segment->name ?? 'N/A'],
                ['Channel ID', $segment->channel_id],
                ['Course', $course ? "{$course->title} (ID: {$course->id})" : 'N/A'],
                ['Raw Video Path', $segment->video ?? 'N/A'],
            ]
        );

        $this->newLine();
        $this->info("Available S3 Paths:");
        $this->line(str_repeat('-', 50));

        $paths = $model->getAllS3Keys();
        $this->table(
            ['Quality', 'S3 Key'],
            [
                ['Low', $paths['low'] ?? 'N/A'],
                ['Med', $paths['med'] ?? 'N/A'],
                ['Hi', $paths['hi'] ?? 'N/A'],
            ]
        );
    }

    private function verifyS3FileExists(string $key): bool
    {
        try {
            $client = $this->getS3Client();
            $client->headObject([
                'Bucket' => TrueFireSegment::S3_BUCKET,
                'Key' => $key,
            ]);
            return true;
        } catch (\Exception $e) {
            $this->line("  S3 verification error: " . get_class($e) . " - " . $e->getMessage());
            return false;
        }
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

    private function createVideoRecord($segment, TrueFireSegment $model, $course, string $s3Key, int $fileSize): Video
    {
        // Check if we already have a record for this segment
        $existing = Video::whereRaw("json_extract(metadata, '$.truefire_segment_id') = ?", [(int) $segment->id])->first();
        
        if ($existing) {
            $this->warn("Updating existing video record: {$existing->id}");
            $existing->update([
                's3_key' => $s3Key,
                'size_bytes' => $fileSize,
                'status' => 'pending',
            ]);
            return $existing;
        }

        // Note: We store TrueFire course_id in metadata, not in the local course_id field
        return Video::create([
            'original_filename' => $segment->name ?? "Segment {$segment->id}",
            's3_key' => $s3Key,
            'mime_type' => 'video/mp4',
            'size_bytes' => $fileSize,
            'status' => 'pending',
            'metadata' => [
                'truefire_segment_id' => $segment->id,
                'truefire_channel_id' => $segment->channel_id,
                'truefire_course_id' => $course?->id,
                'truefire_course_title' => $course?->title,
                's3_bucket' => TrueFireSegment::S3_BUCKET,
                'cloudfront_url' => $model->getCloudFrontUrl(),
            ],
        ]);
    }

    private function showVideoSummary(Video $video): void
    {
        $this->info("Video Record Summary:");
        $this->line(str_repeat('-', 50));
        
        $metadata = $video->metadata ?? [];
        
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $video->id],
                ['Filename', $video->original_filename],
                ['Status', $video->status],
                ['S3 Key', $video->s3_key],
                ['Size', $this->formatBytes($video->size_bytes)],
                ['TrueFire Course', $metadata['truefire_course_title'] ?? 'N/A'],
                ['TrueFire Segment ID', $metadata['truefire_segment_id'] ?? 'N/A'],
            ]
        );
    }

    private function showDryRunPlan($segment, TrueFireSegment $model, $course, string $s3Key): void
    {
        $queueConnection = config('queue.default');
        $queueName = config("queue.connections.{$queueConnection}.queue");
        
        $this->newLine();
        $this->info("Queue Configuration:");
        $this->table(
            ['Setting', 'Value'],
            [
                ['Queue Driver', $queueConnection],
                ['Queue Name', $queueName],
                ['SQS Prefix', $queueConnection === 'sqs' ? config('queue.connections.sqs.prefix') : 'N/A'],
            ]
        );
        
        $this->newLine();
        $this->info("Would perform the following actions:");
        $this->line("  1. Verify S3 file exists: s3://" . TrueFireSegment::S3_BUCKET . "/{$s3Key}");
        $this->line("  2. Create Video record in database");
        $this->line("  3. (with --dispatch) Queue AudioExtractionJob via {$queueConnection}");
        
        $this->newLine();
        $this->info("ECS Pipeline (triggered by SQS message):");
        $this->line("  → audio-extraction-service: Download from S3, extract WAV");
        $this->line("  → transcription-service: Whisper on GPU (g4dn)");
        $this->line("  → music-term-service: spaCy NLP recognition");
        
        $this->newLine();
        $this->info("Database record would contain:");
        $this->table(
            ['Field', 'Value'],
            [
                ['original_filename', $segment->name ?? "Segment {$segment->id}"],
                ['s3_key', $s3Key],
                ['mime_type', 'video/mp4'],
                ['metadata.truefire_segment_id', $segment->id],
                ['metadata.truefire_course_title', $course?->title ?? 'N/A'],
                ['metadata.s3_bucket', TrueFireSegment::S3_BUCKET],
            ]
        );
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
            if (!empty($profile)) {
                $config['profile'] = $profile;
            }
            // If no profile set, SDK uses default credential chain (ECS task role)
            
            $this->s3Client = new S3Client($config);
        }
        return $this->s3Client;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
