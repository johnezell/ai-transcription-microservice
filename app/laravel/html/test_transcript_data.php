<?php

require_once '/var/www/html/vendor/autoload.php';

use App\Models\TruefireSegmentProcessing;
use Illuminate\Support\Facades\DB;

// Initialize Laravel environment
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

try {
    $processing = TruefireSegmentProcessing::where('segment_id', 2231)->first();
    
    if ($processing) {
        echo "✅ Found processing record\n";
        echo "📊 Transcript JSON length: " . strlen($processing->transcript_json) . " characters\n";
        
        // Test JSON decode
        $decoded = json_decode($processing->transcript_json, true);
        
        if ($decoded) {
            echo "✅ JSON decode successful\n";
            echo "🔑 Keys: " . implode(', ', array_keys($decoded)) . "\n";
            
            // Check for word_segments
            if (isset($decoded['word_segments'])) {
                echo "✅ Has word_segments: " . count($decoded['word_segments']) . " segments\n";
                
                // Show first few segments
                echo "📝 First 3 word segments:\n";
                for ($i = 0; $i < min(3, count($decoded['word_segments'])); $i++) {
                    $segment = $decoded['word_segments'][$i];
                    echo "   " . ($i+1) . ". '" . ($segment['word'] ?? 'N/A') . "' ";
                    echo "(score: " . ($segment['score'] ?? 'N/A') . ")\n";
                }
            } else {
                echo "❌ No word_segments found\n";
                echo "🔍 Available keys: " . implode(', ', array_keys($decoded)) . "\n";
            }
            
        } else {
            echo "❌ JSON decode failed: " . json_last_error_msg() . "\n";
            echo "🔍 First 200 chars: " . substr($processing->transcript_json, 0, 200) . "\n";
        }
        
        // Test a simple JSON payload
        $testPayload = [
            'transcription_data' => $decoded,
            'models' => ['llama3.2:3b'],
            'confidence_threshold' => 0.6
        ];
        
        $jsonPayload = json_encode($testPayload);
        
        if ($jsonPayload) {
            echo "✅ Test payload JSON encode successful\n";
            echo "📏 Payload length: " . strlen($jsonPayload) . " characters\n";
        } else {
            echo "❌ Test payload JSON encode failed: " . json_last_error_msg() . "\n";
        }
        
    } else {
        echo "❌ No processing record found for segment 2231\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
} 