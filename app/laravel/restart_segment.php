<?php
// Simple script to restart segment 7959 transcription
require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\TruefireSegmentProcessing;
use App\Jobs\TruefireSegmentAudioExtractionJob;

try {
    $processing = TruefireSegmentProcessing::where('segment_id', 7959)->first();
    
    if (!$processing) {
        // Create new processing record
        $processing = TruefireSegmentProcessing::create([
            'segment_id' => 7959,
            'course_id' => 1,
            'status' => 'ready',
            'progress_percentage' => 0
        ]);
        echo "Created new processing record for segment 7959\n";
    } else {
        // Reset existing record
        $processing->update([
            'status' => 'ready',
            'audio_path' => null,
            'audio_size' => null,
            'audio_duration' => null,
            'error_message' => null,
            'progress_percentage' => 0,
            'audio_extraction_started_at' => null,
            'audio_extraction_completed_at' => null
        ]);
        echo "Reset processing record for segment 7959\n";
    }
    
    // Dispatch the audio extraction job
    TruefireSegmentAudioExtractionJob::dispatch($processing);
    echo "Dispatched audio extraction job for segment 7959\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} 