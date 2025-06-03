<?php

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Storage;
use App\Models\TruefireCourse;

echo "=== FILE PATH MISMATCH DEBUGGING ===\n\n";

// Test course ID 1
$courseId = 1;
echo "Testing Course ID: {$courseId}\n\n";

// Show current filesystem configuration
echo "1. FILESYSTEM CONFIGURATION:\n";
echo "   Default disk: " . config('filesystems.default') . "\n";
echo "   D_DRIVE_PATH: " . env('D_DRIVE_PATH', '/mnt/d_drive') . "\n";
echo "   d_drive root: " . config('filesystems.disks.d_drive.root') . "\n\n";

// Show what the API is looking for
$apiPath = "truefire-courses/{$courseId}";
echo "2. API EXPECTS FILES AT:\n";
echo "   Relative path: {$apiPath}\n";
echo "   Full path: " . Storage::path($apiPath) . "\n";
echo "   Directory exists: " . (Storage::exists($apiPath) ? 'YES' : 'NO') . "\n\n";

// Show where files actually are
$actualPath = "ai-transcription-downloads/truefire-courses/{$courseId}";
echo "3. FILES ACTUALLY LOCATED AT:\n";
echo "   Relative path: {$actualPath}\n";
echo "   Full path: " . Storage::path($actualPath) . "\n";
echo "   Directory exists: " . (Storage::exists($actualPath) ? 'YES' : 'NO') . "\n\n";

// Count files in each location
echo "4. FILE COUNTS:\n";
$apiFiles = collect(Storage::files($apiPath))->filter(fn($file) => str_ends_with($file, '.mp4'));
$actualFiles = collect(Storage::files($actualPath))->filter(fn($file) => str_ends_with($file, '.mp4'));

echo "   Files in API path: " . $apiFiles->count() . "\n";
echo "   Files in actual path: " . $actualFiles->count() . "\n\n";

// Show some actual filenames
if ($actualFiles->count() > 0) {
    echo "5. SAMPLE FILES IN ACTUAL LOCATION:\n";
    foreach ($actualFiles->take(5) as $file) {
        $filename = basename($file);
        echo "   - {$filename}\n";
    }
    echo "\n";
}

// Test Storage::exists() calls for specific files
echo "6. TESTING SPECIFIC FILE EXISTENCE:\n";
$testSegmentId = '7959'; // First file we saw in the list
$apiFilePath = "truefire-courses/{$courseId}/{$testSegmentId}.mp4";
$actualFilePath = "ai-transcription-downloads/truefire-courses/{$courseId}/{$testSegmentId}.mp4";

echo "   API looking for: {$apiFilePath}\n";
echo "   File exists: " . (Storage::exists($apiFilePath) ? 'YES' : 'NO') . "\n";
echo "   Actual file at: {$actualFilePath}\n";
echo "   File exists: " . (Storage::exists($actualFilePath) ? 'YES' : 'NO') . "\n\n";

// Test the download status API logic
echo "7. SIMULATING API DOWNLOAD STATUS CHECK:\n";
try {
    $course = TruefireCourse::with(['channels.segments' => function ($query) {
        $query->withVideo();
    }])->find($courseId);
    
    if ($course) {
        $allSegments = collect();
        foreach ($course->channels as $channel) {
            $allSegments = $allSegments->merge($channel->segments);
        }
        
        $downloadedCount = 0;
        $totalCount = $allSegments->count();
        
        foreach ($allSegments->take(5) as $segment) { // Test first 5 segments
            $filename = "{$segment->id}.mp4";
            $filePath = "truefire-courses/{$courseId}/{$filename}";
            $isDownloaded = Storage::exists($filePath);
            
            if ($isDownloaded) {
                $downloadedCount++;
            }
            
            echo "   Segment {$segment->id}: " . ($isDownloaded ? 'DOWNLOADED' : 'NOT FOUND') . "\n";
        }
        
        echo "\n   API Result: {$downloadedCount}/{$totalCount} segments downloaded\n";
        echo "   Expected: 87 segments downloaded\n\n";
    }
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n\n";
}

echo "=== DIAGNOSIS COMPLETE ===\n";
echo "PROBLEM: API looks in 'truefire-courses/{courseId}/' but files are in 'ai-transcription-downloads/truefire-courses/{courseId}/'\n";
echo "SOLUTION: Either move files or update API path construction\n";