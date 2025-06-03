<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel properly
$app = require_once __DIR__ . '/bootstrap/app.php';

// Boot the application
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Storage;
use App\Models\TruefireCourse;
use App\Models\Segment;

echo "=== Testing API Endpoints with D: Drive Configuration ===\n\n";

// Test 1: Verify filesystem configuration
echo "1. Testing Filesystem Configuration:\n";
echo "   Default disk: " . config('filesystems.default') . "\n";
echo "   D: drive path: " . config('filesystems.disks.d_drive.root') . "\n";
echo "   Storage disk being used: " . Storage::getDefaultDriver() . "\n\n";

// Test 2: Test file existence check
echo "2. Testing File Existence Check:\n";
$testPath = "truefire-courses/test/test-file.mp4";

// Create a test file to verify the path
try {
    Storage::makeDirectory("truefire-courses/test");
    Storage::put($testPath, "test content");
    
    $exists = Storage::exists($testPath);
    $size = Storage::size($testPath);
    $fullPath = Storage::path($testPath);
    
    echo "   Test file created: $testPath\n";
    echo "   File exists: " . ($exists ? 'YES' : 'NO') . "\n";
    echo "   File size: $size bytes\n";
    echo "   Full path: $fullPath\n";
    
    // Clean up
    Storage::delete($testPath);
    echo "   Test file cleaned up\n\n";
    
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n\n";
}

// Test 3: Test with actual course data
echo "3. Testing with Real Course Data:\n";
try {
    $course = TruefireCourse::with(['channels.segments' => function ($query) {
        $query->withVideo()->limit(3); // Just test with first 3 segments
    }])->first();
    
    if ($course) {
        echo "   Testing with course: {$course->id}\n";
        
        $courseDir = "truefire-courses/{$course->id}";
        $segmentCount = 0;
        $downloadedCount = 0;
        
        foreach ($course->channels as $channel) {
            foreach ($channel->segments as $segment) {
                $segmentCount++;
                $filename = "{$segment->id}.mp4";
                $filePath = "{$courseDir}/{$filename}";
                
                $isDownloaded = Storage::exists($filePath);
                if ($isDownloaded) {
                    $downloadedCount++;
                    $fileSize = Storage::size($filePath);
                    $lastModified = Storage::lastModified($filePath);
                    echo "   ✅ Segment {$segment->id}: Downloaded ({$fileSize} bytes, modified: " . date('Y-m-d H:i:s', $lastModified) . ")\n";
                } else {
                    echo "   ❌ Segment {$segment->id}: Not downloaded\n";
                }
            }
        }
        
        echo "   Summary: {$downloadedCount}/{$segmentCount} segments downloaded\n\n";
    } else {
        echo "   No courses found in database\n\n";
    }
    
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n\n";
}

// Test 4: Simulate API endpoint calls
echo "4. Testing API Endpoint Logic:\n";
try {
    if (isset($course) && $course) {
        // Simulate the downloadStatus endpoint logic
        $courseDir = "truefire-courses/{$course->id}";
        $allSegments = collect();
        
        foreach ($course->channels as $channel) {
            $allSegments = $allSegments->merge($channel->segments);
        }
        
        $status = [
            'course_id' => $course->id,
            'total_segments' => $allSegments->count(),
            'downloaded_segments' => 0,
            'storage_path' => Storage::path($courseDir),
            'segments' => []
        ];
        
        foreach ($allSegments as $segment) {
            $newFilename = "{$segment->id}.mp4";
            $legacyFilename = "segment-{$segment->id}.mp4";
            $newFilePath = "{$courseDir}/{$newFilename}";
            $legacyFilePath = "{$courseDir}/{$legacyFilename}";
            
            $isNewFormatDownloaded = Storage::exists($newFilePath);
            $isLegacyFormatDownloaded = Storage::exists($legacyFilePath);
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
                'file_size' => $isDownloaded ? Storage::size($actualFilePath) : null,
                'downloaded_at' => $isDownloaded ? Storage::lastModified($actualFilePath) : null
            ];
        }
        
        echo "   API Response Simulation:\n";
        echo "   Course ID: {$status['course_id']}\n";
        echo "   Total Segments: {$status['total_segments']}\n";
        echo "   Downloaded Segments: {$status['downloaded_segments']}\n";
        echo "   Storage Path: {$status['storage_path']}\n";
        echo "   Download Percentage: " . round(($status['downloaded_segments'] / $status['total_segments']) * 100, 1) . "%\n\n";
        
        // Show first few segments as examples
        echo "   Sample Segments:\n";
        foreach (array_slice($status['segments'], 0, 5) as $segment) {
            $downloadStatus = $segment['is_downloaded'] ? '✅ Downloaded' : '❌ Not Downloaded';
            $sizeInfo = $segment['file_size'] ? ' (' . round($segment['file_size'] / 1024 / 1024, 1) . ' MB)' : '';
            echo "   - {$segment['segment_id']}: {$downloadStatus}{$sizeInfo}\n";
        }
        
    }
    
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n\n";
}

echo "\n=== Test Complete ===\n";
echo "The API endpoints should now correctly check the D: drive location for downloaded files.\n";
echo "Frontend UX will show accurate download status based on files in: " . Storage::path('') . "\n";