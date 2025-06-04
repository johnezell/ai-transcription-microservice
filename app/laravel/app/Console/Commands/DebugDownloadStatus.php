<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use App\Models\TruefireCourse;

class DebugDownloadStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'debug:download-status {courseId=3}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug download status display issues for TrueFire courses';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $courseId = $this->argument('courseId');
        
        $this->info("=== DEBUGGING DOWNLOAD STATUS DISPLAY ISSUE ===");
        $this->info("Course ID: {$courseId}");
        $this->info("Timestamp: " . now()->format('Y-m-d H:i:s'));
        $this->newLine();

        try {
            // Load the course with segments
            $course = TruefireCourse::with(['channels.segments' => function ($query) {
                $query->withVideo(); // Only load segments with valid video fields
            }])->find($courseId);
            
            if (!$course) {
                $this->error("❌ Course {$courseId} not found");
                return 1;
            }
            
            $this->info("✅ Course found: " . ($course->title ?? "Course #{$courseId}"));
            $this->info("Channels: " . $course->channels->count());
            
            // Collect all segments
            $allSegments = collect();
            foreach ($course->channels as $channel) {
                $allSegments = $allSegments->merge($channel->segments);
            }
            
            $this->info("Total segments with video: " . $allSegments->count());
            $this->newLine();
            
            if ($allSegments->isEmpty()) {
                $this->error("❌ No segments with valid video fields found");
                return 1;
            }
            
            // Check course directory and file existence
            $courseDir = "truefire-courses/{$courseId}";
            $this->info("=== FILE SYSTEM ANALYSIS ===");
            $this->info("Course directory: {$courseDir}");
            $this->info("Storage disk: " . config('filesystems.default'));
            $this->info("Storage path: " . storage_path("app/{$courseDir}"));
            
            // Check if directory exists
            $dirExists = Storage::exists($courseDir);
            $this->info("Directory exists: " . ($dirExists ? "✅ YES" : "❌ NO"));
            
            if ($dirExists) {
                $files = Storage::files($courseDir);
                $this->info("Files in directory: " . count($files));
                
                // Show first few files for debugging
                $sampleFiles = array_slice($files, 0, 5);
                foreach ($sampleFiles as $file) {
                    $size = Storage::size($file);
                    $this->info("  - " . basename($file) . " (" . number_format($size) . " bytes)");
                }
                if (count($files) > 5) {
                    $this->info("  ... and " . (count($files) - 5) . " more files");
                }
            }
            
            $this->newLine();
            $this->info("=== SEGMENT FILE ANALYSIS ===");
            $downloadedCount = 0;
            $missingCount = 0;
            $sampleSegments = $allSegments->take(10); // Check first 10 segments
            
            foreach ($sampleSegments as $segment) {
                $this->info("\nSegment ID: {$segment->id}");
                
                // Check both new and legacy formats
                $newFilename = "{$segment->id}.mp4";
                $legacyFilename = "segment-{$segment->id}.mp4";
                $newFilePath = "{$courseDir}/{$newFilename}";
                $legacyFilePath = "{$courseDir}/{$legacyFilename}";
                
                $newExists = Storage::exists($newFilePath);
                $legacyExists = Storage::exists($legacyFilePath);
                
                $this->info("  New format ({$newFilename}): " . ($newExists ? "✅ EXISTS" : "❌ MISSING"));
                $this->info("  Legacy format ({$legacyFilename}): " . ($legacyExists ? "✅ EXISTS" : "❌ MISSING"));
                
                if ($newExists) {
                    $size = Storage::size($newFilePath);
                    $modified = Storage::lastModified($newFilePath);
                    $this->info("  New file size: " . number_format($size) . " bytes");
                    $this->info("  New file modified: " . date('Y-m-d H:i:s', $modified));
                    $downloadedCount++;
                } elseif ($legacyExists) {
                    $size = Storage::size($legacyFilePath);
                    $modified = Storage::lastModified($legacyFilePath);
                    $this->info("  Legacy file size: " . number_format($size) . " bytes");
                    $this->info("  Legacy file modified: " . date('Y-m-d H:i:s', $modified));
                    $downloadedCount++;
                } else {
                    $missingCount++;
                }
            }
            
            $this->newLine();
            $this->info("=== CACHE ANALYSIS ===");
            
            // Check download status cache
            $downloadStatusCacheKey = 'truefire_s3_download_status_' . $courseId;
            $downloadStatusCached = Cache::has($downloadStatusCacheKey);
            $this->info("Download status cache exists: " . ($downloadStatusCached ? "✅ YES" : "❌ NO"));
            
            if ($downloadStatusCached) {
                $cachedStatus = Cache::get($downloadStatusCacheKey);
                $this->info("Cached downloaded count: " . ($cachedStatus['downloaded_segments'] ?? 'N/A'));
                $this->info("Cached total count: " . ($cachedStatus['total_segments'] ?? 'N/A'));
            }
            
            // Check course show cache
            $courseShowCacheKey = 'truefire_course_s3_show_' . $courseId;
            $courseShowCached = Cache::has($courseShowCacheKey);
            $this->info("Course show cache exists: " . ($courseShowCached ? "✅ YES" : "❌ NO"));
            
            // Check queue status cache
            $queueStatusKeys = [
                "processing_segments_{$courseId}",
                "queued_segments_{$courseId}",
                "download_stats_{$courseId}"
            ];
            
            foreach ($queueStatusKeys as $key) {
                $exists = Cache::has($key);
                $this->info("Cache '{$key}': " . ($exists ? "✅ EXISTS" : "❌ MISSING"));
                if ($exists) {
                    $value = Cache::get($key);
                    if (is_array($value)) {
                        $this->info("  Value: " . json_encode($value));
                    } else {
                        $this->info("  Value: " . $value);
                    }
                }
            }
            
            $this->newLine();
            $this->info("=== CONTROLLER LOGIC SIMULATION ===");
            
            // Simulate the controller's downloadStatus method logic
            $simulatedStatus = [
                'course_id' => $courseId,
                'total_segments' => $allSegments->count(),
                'downloaded_segments' => 0,
                'storage_path' => storage_path("app/{$courseDir}"),
                'segments' => []
            ];
            
            foreach ($allSegments as $segment) {
                // Check for both new format (segmentId.mp4) and legacy format (segment-segmentId.mp4)
                $newFilename = "{$segment->id}.mp4";
                $legacyFilename = "segment-{$segment->id}.mp4";
                $newFilePath = "{$courseDir}/{$newFilename}";
                $legacyFilePath = "{$courseDir}/{$legacyFilename}";
                
                $isNewFormatDownloaded = Storage::exists($newFilePath);
                $isLegacyFormatDownloaded = Storage::exists($legacyFilePath);
                $isDownloaded = $isNewFormatDownloaded || $isLegacyFormatDownloaded;
                
                if ($isDownloaded) {
                    $simulatedStatus['downloaded_segments']++;
                }
            }
            
            $this->info("Simulated controller result:");
            $this->info("  Total segments: " . $simulatedStatus['total_segments']);
            $this->info("  Downloaded segments: " . $simulatedStatus['downloaded_segments']);
            $this->info("  Download percentage: " . round(($simulatedStatus['downloaded_segments'] / $simulatedStatus['total_segments']) * 100, 1) . "%");
            
            $this->newLine();
            $this->info("=== DIAGNOSIS SUMMARY ===");
            
            if ($simulatedStatus['downloaded_segments'] > 0) {
                $this->info("✅ Files ARE being downloaded and stored correctly");
                $this->info("✅ Controller logic SHOULD detect downloaded files");
                
                if ($downloadStatusCached) {
                    $this->warn("⚠️  POTENTIAL ISSUE: Download status is cached - may need cache clearing");
                }
                
                if ($courseShowCached) {
                    $this->warn("⚠️  POTENTIAL ISSUE: Course show page is cached - may need cache clearing");
                }
                
                $this->newLine();
                $this->info("🔍 RECOMMENDED ACTIONS:");
                $this->info("1. Clear download status cache");
                $this->info("2. Clear course show cache");
                $this->info("3. Check if frontend is calling the correct API endpoints");
                
            } else {
                $this->error("❌ NO files found - downloads may not be working");
                $this->info("🔍 RECOMMENDED ACTIONS:");
                $this->info("1. Check if V3 jobs are actually running");
                $this->info("2. Check job queue status");
                $this->info("3. Verify storage configuration");
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("❌ Error during diagnosis: " . $e->getMessage());
            $this->error("Stack trace:");
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}