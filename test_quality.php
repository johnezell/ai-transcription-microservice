<?php
// Test script to verify quality metrics

require_once '/var/www/html/vendor/autoload.php';

// Create Laravel application instance
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\LocalTruefireCourse;
use App\Http\Controllers\TruefireCourseController;

echo "=== Testing Quality Metrics Calculation ===\n";

// Get course 1
$course = LocalTruefireCourse::find(1);
if (!$course) {
    echo "Course 1 not found\n";
    exit(1);
}

// Create controller instance 
$controller = new TruefireCourseController();

// Use reflection to call the private method
$reflection = new ReflectionClass($controller);
$method = $reflection->getMethod('calculateCourseQualityMetrics');
$method->setAccessible(true);

// Calculate quality metrics
$qualityMetrics = $method->invoke($controller, $course);

echo "Quality Metrics Results:\n";
echo "- Overall Quality: " . ($qualityMetrics['overall_quality'] ?? 'null') . "\n";
echo "- Grade: " . ($qualityMetrics['grade'] ?? 'null') . "\n";
echo "- Average Confidence: " . ($qualityMetrics['average_confidence'] ?? 'null') . "\n";
echo "- Music Terms Found: " . ($qualityMetrics['music_terms_found'] ?? 'null') . "\n";
echo "- Completion Rate: " . ($qualityMetrics['completion_rate'] ?? 'null') . "%\n";
echo "- Segments Analyzed: " . ($qualityMetrics['segments_analyzed'] ?? 'null') . "\n";
echo "- Total Segments: " . ($qualityMetrics['total_segments'] ?? 'null') . "\n";

if (isset($qualityMetrics['quality_distribution'])) {
    echo "Quality Distribution:\n";
    echo "  - Excellent: " . $qualityMetrics['quality_distribution']['excellent'] . "\n";
    echo "  - Good: " . $qualityMetrics['quality_distribution']['good'] . "\n";
    echo "  - Fair: " . $qualityMetrics['quality_distribution']['fair'] . "\n";
    echo "  - Poor: " . $qualityMetrics['quality_distribution']['poor'] . "\n";
}

if (isset($qualityMetrics['recommendations']) && count($qualityMetrics['recommendations']) > 0) {
    echo "Recommendations:\n";
    foreach ($qualityMetrics['recommendations'] as $rec) {
        echo "  - [{$rec['priority']}] {$rec['message']}\n";
    }
}

echo "\nTest completed successfully!\n"; 