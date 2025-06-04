<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use App\Models\TruefireCourse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== DEBUGGING API DETECTION ISSUE ===\n";
echo "Investigating why downloadStatus() API returns 0 downloaded files\n\n";

$courseId = 3;

try {
    // Step 1: Replicate the exact logic from downloadStatus() method
    echo "1. REPLICATING downloadStatus() METHOD LOGIC\n";
    
    $course = TruefireCourse::find($courseId);
    if (!$course) {
        echo "   ❌ Course {$courseId} not found\n";
        exit(1);
    }
    
    // Load segments exactly as the API method does
    $course = $course->load(['channels.segments' => function ($query) {
        $query->withVideo(); // Only load segments with valid video fields
    }]);
    
    $courseDir = "ai-transcription-downloads/truefire-courses/{$courseId}";
    
    // Collect segments from all channels (exactly as API does)
    $allSegments = collect();
    foreach ($course->channels as $channel) {
        $allSegments = $allSegments->merge($channel->segments);
    }
    
    echo "   Total segments with video: " . $allSegments->count() . "\n";
    echo "   Course directory: {$courseDir}\n";
    
    $disk = config('filesystems.default');
    echo "   Storage disk: {$disk}\n";
    
    // Check disk configuration
    $diskConfig = config("filesystems.disks.{$disk}");
    echo "   Disk root path: " . ($diskConfig['root'] ?? 'not set') . "\n";
    echo "   Full storage path: " . storage_path("app/{$courseDir}") . "\n\n";
    
    // Step 2: Test file detection for each segment
    echo "2. TESTING FILE DETECTION FOR EACH SEGMENT\n";
    
    $detectedFiles = 0;
    $segments = [];
    
    foreach ($allSegments as $index => $segment) {
        if ($index >= 5) break; // Test first 5 segments
        
        // Check for both formats exactly as API does
        $newFilename = "{$segment->id}.mp4";
        $legacyFilename = "segment-{$segment->id}.mp4";
        $newFilePath = "{$courseDir}/{$newFilename}";
        $legacyFilePath = "{$courseDir}/{$legacyFilename}";
        
        echo "   Segment {$segment->id}:\n";
        echo "     New format path: {$newFilePath}\n";
        echo "     Legacy format path: {$legacyFilePath}\n";
        
        // Test file existence using Storage facade (as API does)
        $isNewFormatDownloaded = Storage::disk($disk)->exists($newFilePath);
        $isLegacyFormatDownloaded = Storage::disk($disk)->exists($legacyFilePath);
        $isDownloaded = $isNewFormatDownloaded || $isLegacyFormatDownloaded;
        
        echo "     New format exists: " . ($isNewFormatDownloaded ? 'YES' : 'NO') . "\n";
        echo "     Legacy format exists: " . ($isLegacyFormatDownloaded ? 'YES' : 'NO') . "\n";
        echo "     Combined result: " . ($isDownloaded ? 'DOWNLOADED' : 'NOT DOWNLOADED') . "\n";
        
        if ($isDownloaded) {
            $detectedFiles++;
            $actualFilename = $isNewFormatDownloaded ? $newFilename : $legacyFilename;
            $actualFilePath = $isNewFormatDownloaded ? $newFilePath : $legacyFilePath;
            
            try {
                $fileSize = Storage::disk($disk)->size($actualFilePath);
                $lastModified = Storage::disk($disk)->lastModified($actualFilePath);
                echo "     File size: " . number_format($fileSize) . " bytes\n";
                echo "     Last modified: " . date('Y-m-d H:i:s', $lastModified) . "\n";
            } catch (\Exception $e) {
                echo "     ❌ Error getting file info: " . $e->getMessage() . "\n";
            }
        }
        
        // Build segment data exactly as API does
        $actualFilename = $isNewFormatDownloaded ? $newFilename : ($isLegacyFormatDownloaded ? $legacyFilename : $newFilename);
        $actualFilePath = $isNewFormatDownloaded ? $newFilePath : ($isLegacyFormatDownloaded ? $legacyFilePath : $newFilePath);
        
        $segments[] = [
            'segment_id' => $segment->id,
            'title' => $segment->title ?? "Segment #{$segment->id}",
            'filename' => $actualFilename,
            'is_downloaded' => $isDownloaded,
            'file_size' => $isDownloaded ? Storage::disk($disk)->size($actualFilePath) : null,
            'downloaded_at' => $isDownloaded ? Storage::disk($disk)->lastModified($actualFilePath) : null
        ];
        
        echo "\n";
    }
    
    echo "   Files detected by API logic: {$detectedFiles}/5 (first 5 segments)\n\n";
    
    // Step 3: Test direct file system access
    echo "3. TESTING DIRECT FILE SYSTEM ACCESS\n";
    
    $fullPath = storage_path("app/{$courseDir}");
    echo "   Checking directory: {$fullPath}\n";
    
    if (is_dir($fullPath)) {
        echo "   ✅ Directory exists\n";
        $files = scandir($fullPath);
        $mp4Files = array_filter($files, function($file) {
            return pathinfo($file, PATHINFO_EXTENSION) === 'mp4';
        });
        echo "   MP4 files found: " . count($mp4Files) . "\n";
        
        foreach (array_slice($mp4Files, 0, 5) as $file) {
            $filePath = $fullPath . '/' . $file;
            $fileSize = filesize($filePath);
            echo "     - {$file} (" . number_format($fileSize) . " bytes)\n";
        }
    } else {
        echo "   ❌ Directory does not exist\n";
    }
    
    echo "\n";
    
    // Step 4: Test cache impact
    echo "4. TESTING CACHE IMPACT\n";
    
    $cacheKey = 'truefire_s3_download_status_' . $courseId;
    echo "   Cache key: {$cacheKey}\n";
    
    if (Cache::has($cacheKey)) {
        echo "   ⚠️  Cache exists - this might be serving stale data\n";
        $cachedData = Cache::get($cacheKey);
        echo "   Cached downloaded_segments: " . ($cachedData['downloaded_segments'] ?? 'unknown') . "\n";
        
        // Clear cache and test again
        Cache::forget($cacheKey);
        echo "   ✅ Cache cleared\n";
    } else {
        echo "   ✅ No cache found\n";
    }
    
    echo "\n";
    
    // Step 5: Build status exactly as API method does
    echo "5. BUILDING STATUS EXACTLY AS API METHOD\n";
    
    $status = [
        'course_id' => $courseId,
        'total_segments' => $allSegments->count(),
        'downloaded_segments' => 0,
        'storage_path' => storage_path("app/{$courseDir}"),
        'segments' => []
    ];
    
    foreach ($allSegments as $segment) {
        // Replicate exact API logic
        $newFilename = "{$segment->id}.mp4";
        $legacyFilename = "segment-{$segment->id}.mp4";
        $newFilePath = "{$courseDir}/{$newFilename}";
        $legacyFilePath = "{$courseDir}/{$legacyFilename}";
        
        $disk = config('filesystems.default');
        $isNewFormatDownloaded = Storage::disk($disk)->exists($newFilePath);
        $isLegacyFormatDownloaded = Storage::disk($disk)->exists($legacyFilePath);
        $isDownloaded = $isNewFormatDownloaded || $isLegacyFormatDownloaded;
        
        $actualFilename = $isNewFormatDownloaded ? $newFilename : ($isLegacyFormatDownloaded ? $legacyFilename : $newFilename);
        $actualFilePath = $isNewFormatDownloaded ? $newFilePath : ($isLegacyFormatDownloaded ? $legacyFilePath : $newFilePath);
        
        if ($isDownloaded) {
            $status['downloaded_segments']++;
        }
        
        $status['segments'][] = [
            'segment_id' => $segment->id,
            'title' => $segment->title ?? "Segment #{$segment->id}",
            'filename' => $actualFilename,
            'is_downloaded' => $isDownloaded,
            'file_size' => $isDownloaded ? Storage::disk($disk)->size($actualFilePath) : null,
            'downloaded_at' => $isDownloaded ? Storage::disk($disk)->lastModified($actualFilePath) : null
        ];
    }
    
    echo "   Final status:\n";
    echo "     course_id: {$status['course_id']}\n";
    echo "     total_segments: {$status['total_segments']}\n";
    echo "     downloaded_segments: {$status['downloaded_segments']}\n";
    echo "     storage_path: {$status['storage_path']}\n";
    echo "     segments count: " . count($status['segments']) . "\n\n";
    
    // Step 6: Compare with actual API call
    echo "6. COMPARING WITH ACTUAL API RESPONSE\n";
    
    // Make actual API call
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost/truefire-courses/3/download-status');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    $apiResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $apiData = json_decode($apiResponse, true);
        echo "   API Response:\n";
        echo "     downloaded_segments: " . ($apiData['downloaded_segments'] ?? 'unknown') . "\n";
        echo "     total_segments: " . ($apiData['total_segments'] ?? 'unknown') . "\n";
        
        echo "\n   COMPARISON:\n";
        echo "     Direct logic result: {$status['downloaded_segments']} downloaded\n";
        echo "     API response result: " . ($apiData['downloaded_segments'] ?? 'unknown') . " downloaded\n";
        
        if ($status['downloaded_segments'] !== ($apiData['downloaded_segments'] ?? 0)) {
            echo "     ⚠️  MISMATCH DETECTED!\n";
            echo "     This suggests caching or other middleware is interfering\n";
        } else {
            echo "     ✅ Results match\n";
        }
    } else {
        echo "   ❌ API call failed with status: {$httpCode}\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Error during debugging: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}