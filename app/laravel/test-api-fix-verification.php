<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Storage;
use App\Models\TruefireCourse;

echo "=== API FIX VERIFICATION ===\n\n";

// Test course ID 1
$courseId = 1;
echo "Testing Course ID: {$courseId}\n\n";

// Test the actual API logic from the updated controller
echo "1. TESTING UPDATED API LOGIC:\n";
try {
    $course = TruefireCourse::with(['channels.segments' => function ($query) {
        $query->withVideo();
    }])->find($courseId);
    
    if ($course) {
        // Use the UPDATED path from the controller
        $courseDir = "ai-transcription-downloads/truefire-courses/{$courseId}";
        echo "   Updated course directory: {$courseDir}\n";
        echo "   Full path: " . Storage::path($courseDir) . "\n";
        echo "   Directory exists: " . (Storage::exists($courseDir) ? 'YES' : 'NO') . "\n\n";
        
        $allSegments = collect();
        foreach ($course->channels as $channel) {
            $allSegments = $allSegments->merge($channel->segments);
        }
        
        $downloadedCount = 0;
        $totalCount = $allSegments->count();
        
        echo "2. TESTING FILE EXISTENCE WITH NEW PATH:\n";
        foreach ($allSegments->take(10) as $segment) { // Test first 10 segments
            $filename = "{$segment->id}.mp4";
            $filePath = "{$courseDir}/{$filename}";
            $isDownloaded = Storage::exists($filePath);
            
            if ($isDownloaded) {
                $downloadedCount++;
            }
            
            echo "   Segment {$segment->id}: " . ($isDownloaded ? 'FOUND ✓' : 'NOT FOUND ✗') . "\n";
        }
        
        echo "\n3. FINAL RESULTS:\n";
        echo "   Total segments: {$totalCount}\n";
        echo "   Downloaded segments (first 10 tested): {$downloadedCount}/10\n";
        echo "   Expected result: All segments should be FOUND\n\n";
        
        // Test the download status API endpoint simulation
        echo "4. SIMULATING downloadStatus() API METHOD:\n";
        $allDownloadedCount = 0;
        foreach ($allSegments as $segment) {
            $newFilename = "{$segment->id}.mp4";
            $legacyFilename = "segment-{$segment->id}.mp4";
            $newFilePath = "{$courseDir}/{$newFilename}";
            $legacyFilePath = "{$courseDir}/{$legacyFilename}";
            
            $isNewFormatDownloaded = Storage::exists($newFilePath);
            $isLegacyFormatDownloaded = Storage::exists($legacyFilePath);
            $isDownloaded = $isNewFormatDownloaded || $isLegacyFormatDownloaded;
            
            if ($isDownloaded) {
                $allDownloadedCount++;
            }
        }
        
        echo "   API would report: {$allDownloadedCount}/{$totalCount} segments downloaded\n";
        echo "   This should now show 87/87 instead of 0/87!\n\n";
        
    } else {
        echo "   Course not found!\n\n";
    }
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n\n";
}

echo "5. TESTING DIRECT API ENDPOINT:\n";
echo "   You can now test: http://localhost:8080/truefire-courses/1/download-status\n";
echo "   Expected result: Should show 87 downloaded segments\n\n";

echo "=== VERIFICATION COMPLETE ===\n";
echo "If all segments show 'FOUND ✓', the fix is working correctly!\n";