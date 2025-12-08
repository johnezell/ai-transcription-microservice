<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TrueFire\TrueFireCourse;
use App\Models\TrueFire\TrueFireChannel;
use App\Models\TrueFire\TrueFireSegment;
use Illuminate\Support\Facades\DB;

class TestTrueFireConnection extends Command
{
    protected $signature = 'truefire:test {course_id=85 : The course ID to test}';
    protected $description = 'Test TrueFire database connection and S3 path mapping';

    public function handle()
    {
        $courseId = $this->argument('course_id');
        
        $this->info("Testing TrueFire database connection...");
        $this->newLine();
        
        // Test raw connection first
        try {
            $result = DB::connection('truefire')->select('SELECT 1 as connected');
            $this->info("✓ Database connection successful");
        } catch (\Exception $e) {
            $this->error("✗ Database connection failed: " . $e->getMessage());
            return 1;
        }
        
        $this->newLine();
        $this->info("Fetching course ID: {$courseId}");
        $this->line(str_repeat('-', 50));
        
        // Fetch course
        $course = TrueFireCourse::find($courseId);
        
        if (!$course) {
            $this->error("Course {$courseId} not found!");
            return 1;
        }
        
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $course->id],
                ['Title', $course->title ?? 'N/A'],
                ['Status', $course->status ?? 'N/A'],
                ['Permalink', $course->perma_link ?? 'N/A'],
            ]
        );
        
        $this->newLine();
        $this->info("Fetching channels for course...");
        $this->line(str_repeat('-', 50));
        
        // Fetch channels using raw query (to verify schema.table works)
        $channels = DB::connection('truefire')
            ->table('channels.channels')
            ->where('courseid', $courseId)
            ->get();
        
        $this->info("Found {$channels->count()} channel(s)");
        
        foreach ($channels as $channel) {
            $this->line("  Channel ID: {$channel->id}");
        }
        
        $this->newLine();
        $this->info("Fetching segments (videos) for course...");
        $this->line(str_repeat('-', 50));
        
        // Fetch segments
        $segments = DB::connection('truefire')
            ->select("
                SELECT 
                    s.id,
                    s.video,
                    s.name,
                    ch.id as channel_id
                FROM channels.channels ch
                JOIN channels.segments s ON s.channel_id = ch.id
                WHERE ch.courseid = ?
                ORDER BY s.id
                LIMIT 10
            ", [$courseId]);
        
        $this->info("Found " . count($segments) . " segment(s) (showing first 10)");
        $this->newLine();
        
        // Display segments with S3 path mapping
        $tableData = [];
        foreach ($segments as $segment) {
            // Create a TrueFireSegment model to use the helper methods
            $segmentModel = new TrueFireSegment((array) $segment);
            
            $tableData[] = [
                $segment->id,
                $segment->name ?? 'N/A',
                $segment->video ?? 'N/A',
                $segmentModel->getS3Key() ?? 'N/A',
            ];
        }
        
        $this->table(
            ['Segment ID', 'Name', 'Raw Video Path', 'S3 Key (hi quality)'],
            $tableData
        );
        
        $this->newLine();
        $this->info("Sample S3 paths for first segment:");
        $this->line(str_repeat('-', 50));
        
        if (count($segments) > 0) {
            $firstSegment = new TrueFireSegment((array) $segments[0]);
            
            $this->table(
                ['Quality', 'S3 URI', 'CloudFront URL'],
                [
                    ['Low', $firstSegment->getS3Uri(TrueFireSegment::QUALITY_LOW), $firstSegment->getCloudFrontUrl(TrueFireSegment::QUALITY_LOW)],
                    ['Med', $firstSegment->getS3Uri(TrueFireSegment::QUALITY_MED), $firstSegment->getCloudFrontUrl(TrueFireSegment::QUALITY_MED)],
                    ['Hi', $firstSegment->getS3Uri(TrueFireSegment::QUALITY_HI), $firstSegment->getCloudFrontUrl(TrueFireSegment::QUALITY_HI)],
                ]
            );
        }
        
        $this->newLine();
        $this->info("✓ Test completed successfully!");
        
        return 0;
    }
}

