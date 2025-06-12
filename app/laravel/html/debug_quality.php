<?php
// Debug script to check quality metrics

echo "=== Debug Quality Metrics ===\n";

// Check if we have completed segments for course 1
$completedCount = \App\Models\TruefireSegmentProcessing::where('course_id', 1)
    ->where('status', 'completed')
    ->count();

echo "Completed segments for course 1: $completedCount\n";

if ($completedCount > 0) {
    // Get first completed segment
    $firstCompleted = \App\Models\TruefireSegmentProcessing::where('course_id', 1)
        ->where('status', 'completed')
        ->first();
    
    echo "First completed segment: {$firstCompleted->segment_id}\n";
    echo "JSON path: {$firstCompleted->transcript_json_path}\n";
    echo "File exists: " . (file_exists($firstCompleted->transcript_json_path) ? 'YES' : 'NO') . "\n";
    
    if (file_exists($firstCompleted->transcript_json_path)) {
        $jsonContent = file_get_contents($firstCompleted->transcript_json_path);
        $data = json_decode($jsonContent, true);
        
        echo "\n=== JSON Structure Analysis ===\n";
        echo "Has segments: " . (isset($data['segments']) ? 'YES' : 'NO') . "\n";
        
        if (isset($data['segments']) && is_array($data['segments']) && count($data['segments']) > 0) {
            $firstSegment = $data['segments'][0];
            echo "First segment has words: " . (isset($firstSegment['words']) ? 'YES' : 'NO') . "\n";
            
            if (isset($firstSegment['words']) && is_array($firstSegment['words']) && count($firstSegment['words']) > 0) {
                $firstWord = $firstSegment['words'][0];
                echo "First word structure:\n";
                echo "  - word: " . ($firstWord['word'] ?? 'missing') . "\n";
                echo "  - probability: " . ($firstWord['probability'] ?? 'missing') . "\n";
                echo "  - score: " . ($firstWord['score'] ?? 'missing') . "\n";
                echo "  - confidence: " . ($firstWord['confidence'] ?? 'missing') . "\n";
            }
        }
        
        echo "\n=== Quality Metrics Fields ===\n";
        echo "Has quality_metrics: " . (isset($data['quality_metrics']) ? 'YES' : 'NO') . "\n";
        echo "Has overall_quality_score: " . (isset($data['overall_quality_score']) ? 'YES' : 'NO') . "\n";
        echo "Has guitar_term_evaluation: " . (isset($data['guitar_term_evaluation']) ? 'YES' : 'NO') . "\n";
        
        if (isset($data['quality_metrics'])) {
            echo "Quality metrics keys: " . implode(', ', array_keys($data['quality_metrics'])) . "\n";
        }
        
        if (isset($data['guitar_term_evaluation'])) {
            echo "Guitar evaluation keys: " . implode(', ', array_keys($data['guitar_term_evaluation'])) . "\n";
        }
    }
} 