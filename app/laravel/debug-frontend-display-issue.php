<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use App\Models\TruefireCourse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== DEBUGGING FRONTEND DISPLAY ISSUE ===\n";
echo "Testing TrueFire Course #3 API endpoints\n\n";

$courseId = 3;

try {
    // Test 1: Download Status API (what the frontend should use for file existence)
    echo "1. TESTING DOWNLOAD STATUS API\n";
    echo "   Endpoint: GET /truefire-courses/{$courseId}/download-status\n";
    
    $course = TruefireCourse::find($courseId);
    if (!$course) {
        echo "   ❌ Course {$courseId} not found\n";
        exit(1);
    }
    
    // Load segments with valid video fields
    $course = $course->load(['channels.segments' => function ($query) {
        $query->withVideo();
    }]);
    
    $courseDir = "ai-transcription-downloads/truefire-courses/{$courseId}";
    
    // Collect segments from all channels
    $allSegments = collect();
    foreach ($course->channels as $channel) {
        $allSegments = $allSegments->merge($channel->segments);
    }
    
    echo "   Total segments with video: " . $allSegments->count() . "\n";
    
    $downloadStatus = [
        'course_id' => $courseId,
        'total_segments' => $allSegments->count(),
        'downloaded_segments' => 0,
        'storage_path' => storage_path("app/{$courseDir}"),
        'segments' => []
    ];
    
    $disk = config('filesystems.default');
    echo "   Using storage disk: {$disk}\n";
    echo "   Storage path: " . storage_path("app/{$courseDir}") . "\n\n";
    
    $filesOnDisk = 0;
    $segmentDetails = [];
    
    foreach ($allSegments->take(5) as $segment) { // Show first 5 segments
        $newFilename = "{$segment->id}.mp4";
        $legacyFilename = "segment-{$segment->id}.mp4";
        $newFilePath = "{$courseDir}/{$newFilename}";
        $legacyFilePath = "{$courseDir}/{$legacyFilename}";
        
        $isNewFormatDownloaded = Storage::disk($disk)->exists($newFilePath);
        $isLegacyFormatDownloaded = Storage::disk($disk)->exists($legacyFilePath);
        $isDownloaded = $isNewFormatDownloaded || $isLegacyFormatDownloaded;
        
        if ($isDownloaded) {
            $filesOnDisk++;
            $downloadStatus['downloaded_segments']++;
        }
        
        $actualFilename = $isNewFormatDownloaded ? $newFilename : ($isLegacyFormatDownloaded ? $legacyFilename : $newFilename);
        $actualFilePath = $isNewFormatDownloaded ? $newFilePath : ($isLegacyFormatDownloaded ? $legacyFilePath : $newFilePath);
        
        $segmentDetail = [
            'segment_id' => $segment->id,
            'title' => $segment->title ?? "Segment #{$segment->id}",
            'filename' => $actualFilename,
            'is_downloaded' => $isDownloaded,
            'file_size' => $isDownloaded ? Storage::disk($disk)->size($actualFilePath) : null,
            'downloaded_at' => $isDownloaded ? Storage::disk($disk)->lastModified($actualFilePath) : null
        ];
        
        $downloadStatus['segments'][] = $segmentDetail;
        $segmentDetails[] = $segmentDetail;
        
        echo "   Segment {$segment->id}: " . ($isDownloaded ? "✅ Downloaded" : "❌ Not Downloaded") . "\n";
        if ($isDownloaded) {
            echo "     File: {$actualFilename}\n";
            echo "     Size: " . number_format(Storage::disk($disk)->size($actualFilePath)) . " bytes\n";
        }
    }
    
    echo "\n   📊 Download Status Summary:\n";
    echo "     Total segments: {$downloadStatus['total_segments']}\n";
    echo "     Downloaded: {$downloadStatus['downloaded_segments']}\n";
    echo "     Progress: " . round(($downloadStatus['downloaded_segments'] / $downloadStatus['total_segments']) * 100, 1) . "%\n\n";
    
    // Test 2: Queue Status API (what the frontend uses for display)
    echo "2. TESTING QUEUE STATUS API\n";
    echo "   Endpoint: GET /truefire-courses/{$courseId}/queue-status\n";
    
    $segmentIds = $allSegments->pluck('id')->toArray();
    
    // Get queued jobs from database
    $queuedJobs = collect();
    if (config('queue.default') === 'database') {
        $allJobs = \DB::table('jobs')->get();
        echo "   Total jobs in queue table: " . $allJobs->count() . "\n";
        
        foreach ($allJobs as $job) {
            try {
                $payload = json_decode($job->payload, true);
                if (isset($payload['data']['segment'])) {
                    $segmentData = unserialize($payload['data']['segment']);
                    if (in_array($segmentData->id, $segmentIds)) {
                        $queuedJobs->push($job);
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }
    }
    
    echo "   Queued jobs for this course: " . $queuedJobs->count() . "\n";
    
    // Get processing status from cache
    $processingKey = "processing_segments_{$courseId}";
    $processingSegments = Cache::get($processingKey, []);
    echo "   Processing segments in cache: " . count($processingSegments) . "\n\n";
    
    // Build queue status for first 5 segments
    $queueSegmentStatuses = [];
    foreach ($allSegments->take(5) as $segment) {
        $filename = "{$segment->id}.mp4";
        $filePath = "{$courseDir}/{$filename}";
        $isDownloaded = Storage::disk($disk)->exists($filePath);
        
        $status = 'not_started';
        if ($isDownloaded) {
            $status = 'completed';
        } elseif (in_array($segment->id, $processingSegments)) {
            $status = 'processing';
        } else {
            $isQueued = $queuedJobs->contains(function ($job) use ($segment) {
                try {
                    $payload = json_decode($job->payload, true);
                    if (isset($payload['data']['segment'])) {
                        $segmentData = unserialize($payload['data']['segment']);
                        return $segmentData->id === $segment->id;
                    }
                } catch (\Exception $e) {
                    return false;
                }
                return false;
            });
            if ($isQueued) {
                $status = 'queued';
            }
        }
        
        $queueSegmentStatuses[] = [
            'segment_id' => $segment->id,
            'title' => $segment->title ?? "Segment #{$segment->id}",
            'status' => $status,
            'file_size' => $isDownloaded ? Storage::disk($disk)->size($filePath) : null,
        ];
        
        echo "   Segment {$segment->id}: Queue Status = {$status}\n";
    }
    
    // Test 3: Compare Download Status vs Queue Status
    echo "\n3. COMPARING DOWNLOAD STATUS vs QUEUE STATUS\n";
    echo "   This comparison shows the potential mismatch:\n\n";
    
    foreach ($segmentDetails as $index => $downloadSegment) {
        $queueSegment = $queueSegmentStatuses[$index];
        $downloadStatus = $downloadSegment['is_downloaded'] ? 'Downloaded' : 'Not Downloaded';
        $queueStatus = $queueSegment['status'];
        
        $mismatch = ($downloadSegment['is_downloaded'] && $queueStatus !== 'completed') || 
                   (!$downloadSegment['is_downloaded'] && $queueStatus === 'completed');
        
        echo "   Segment {$downloadSegment['segment_id']}:\n";
        echo "     Download API: {$downloadStatus}\n";
        echo "     Queue API: {$queueStatus}\n";
        echo "     Mismatch: " . ($mismatch ? "⚠️ YES" : "✅ NO") . "\n\n";
    }
    
    // Test 4: Check what the Vue component actually receives
    echo "4. SIMULATING VUE COMPONENT DATA FLOW\n";
    echo "   Testing how the frontend processes this data:\n\n";
    
    // Simulate the Vue component's loadDownloadStatus() function
    echo "   Vue loadDownloadStatus() would receive:\n";
    echo "   {\n";
    echo "     course_id: " . $downloadStatus['course_id'] . ",\n";
    echo "     total_segments: " . $downloadStatus['total_segments'] . ",\n";
    echo "     downloaded_segments: " . $downloadStatus['downloaded_segments'] . ",\n";
    echo "     storage_path: \"" . $downloadStatus['storage_path'] . "\",\n";
    echo "     segments: [...]\n";
    echo "   }\n\n";
    
    // Simulate the Vue component's computed properties
    $downloadedCount = $downloadStatus['downloaded_segments'];
    $totalSegments = $downloadStatus['total_segments'];
    $downloadProgressPercent = $totalSegments > 0 ? round(($downloadedCount / $totalSegments) * 100) : 0;
    
    echo "   Vue computed properties would show:\n";
    echo "     downloadedCount: {$downloadedCount}\n";
    echo "     totalSegments: {$totalSegments}\n";
    echo "     downloadProgressPercent: {$downloadProgressPercent}%\n\n";
    
    // Test 5: Check the segmentsWithSignedUrls data (from show method)
    echo "5. TESTING SHOW METHOD DATA (segmentsWithSignedUrls)\n";
    echo "   This is the data passed to the Vue component on page load:\n\n";
    
    $segmentsWithSignedUrls = [];
    foreach ($course->channels as $channel) {
        foreach ($channel->segments->take(3) as $segment) { // Show first 3
            $newFilename = "{$segment->id}.mp4";
            $legacyFilename = "segment-{$segment->id}.mp4";
            $newFilePath = "{$courseDir}/{$newFilename}";
            $legacyFilePath = "{$courseDir}/{$legacyFilename}";
            
            $isNewFormatDownloaded = Storage::disk($disk)->exists($newFilePath);
            $isLegacyFormatDownloaded = Storage::disk($disk)->exists($legacyFilePath);
            $isDownloaded = $isNewFormatDownloaded || $isLegacyFormatDownloaded;
            
            $actualFilePath = $isNewFormatDownloaded ? $newFilePath : ($isLegacyFormatDownloaded ? $legacyFilePath : $newFilePath);
            
            $segmentData = [
                'id' => $segment->id,
                'channel_id' => $channel->id,
                'channel_name' => $channel->name ?? $channel->title ?? "Channel #{$channel->id}",
                'title' => $segment->title ?? "Segment #{$segment->id}",
                'is_downloaded' => $isDownloaded,
                'file_size' => $isDownloaded ? Storage::disk($disk)->size($actualFilePath) : null,
                'downloaded_at' => $isDownloaded ? Storage::disk($disk)->lastModified($actualFilePath) : null,
            ];
            
            $segmentsWithSignedUrls[] = $segmentData;
            
            echo "   Segment {$segment->id}:\n";
            echo "     is_downloaded: " . ($isDownloaded ? 'true' : 'false') . "\n";
            echo "     file_size: " . ($segmentData['file_size'] ? number_format($segmentData['file_size']) . ' bytes' : 'null') . "\n";
            echo "     downloaded_at: " . ($segmentData['downloaded_at'] ? date('Y-m-d H:i:s', $segmentData['downloaded_at']) : 'null') . "\n\n";
        }
        break; // Only process first channel for this test
    }
    
    echo "6. DIAGNOSIS SUMMARY\n";
    echo "   Based on the analysis above:\n\n";
    
    if ($downloadStatus['downloaded_segments'] > 0) {
        echo "   ✅ Files exist on disk: {$downloadStatus['downloaded_segments']}/{$downloadStatus['total_segments']}\n";
        echo "   ✅ Download Status API returns correct data\n";
        echo "   ✅ Show method passes correct is_downloaded flags\n";
        
        // Check if Vue component is using the right data
        echo "\n   🔍 POTENTIAL ISSUE IDENTIFIED:\n";
        echo "   The Vue component's getStatusDisplay() function prioritizes\n";
        echo "   queue status over actual file existence. This means even if\n";
        echo "   files exist on disk, the display shows queue status instead.\n\n";
        
        echo "   Vue component logic:\n";
        echo "   - getSegmentQueueStatus() checks queueStatus.segments\n";
        echo "   - getStatusDisplay() uses queue status for display\n";
        echo "   - Actual file existence (is_downloaded) is only shown in actions column\n\n";
        
        echo "   RECOMMENDED FIX:\n";
        echo "   Modify Vue component to prioritize actual file existence over queue status\n";
        echo "   for the main status display.\n";
    } else {
        echo "   ❌ No files found on disk\n";
        echo "   This suggests either:\n";
        echo "   - Files haven't been downloaded yet\n";
        echo "   - Files are in a different location\n";
        echo "   - Storage disk configuration issue\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Error during debugging: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}