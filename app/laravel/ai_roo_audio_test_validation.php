<?php

/**
 * Audio Extraction Testing Implementation Validation Script
 * 
 * This script validates that Phase 1 implementation is working correctly:
 * - Database migration applied
 * - TranscriptionLog model updated
 * - AudioExtractionTestJob class created
 * - TruefireCourseController methods added
 * - Routes registered
 */

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;

// Bootstrap Laravel application
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Audio Extraction Testing Implementation Validation ===\n\n";

// 1. Validate Database Schema
echo "1. Checking database schema...\n";
try {
    $hasTestFields = Schema::hasColumns('transcription_logs', [
        'is_test_extraction',
        'test_quality_level', 
        'audio_quality_metrics',
        'extraction_settings'
    ]);
    
    if ($hasTestFields) {
        echo "   ✓ All required fields exist in transcription_logs table\n";
    } else {
        echo "   ✗ Missing required fields in transcription_logs table\n";
    }
} catch (Exception $e) {
    echo "   ✗ Database schema check failed: " . $e->getMessage() . "\n";
}

// 2. Validate TranscriptionLog Model
echo "\n2. Checking TranscriptionLog model...\n";
try {
    $model = new \App\Models\TranscriptionLog();
    $fillable = $model->getFillable();
    
    $requiredFields = ['is_test_extraction', 'test_quality_level', 'audio_quality_metrics', 'extraction_settings'];
    $hasAllFields = true;
    
    foreach ($requiredFields as $field) {
        if (!in_array($field, $fillable)) {
            echo "   ✗ Missing fillable field: $field\n";
            $hasAllFields = false;
        }
    }
    
    if ($hasAllFields) {
        echo "   ✓ All required fields are fillable in TranscriptionLog model\n";
    }
    
    // Check casts
    $casts = $model->getCasts();
    if (isset($casts['is_test_extraction']) && $casts['is_test_extraction'] === 'boolean') {
        echo "   ✓ is_test_extraction field properly cast to boolean\n";
    } else {
        echo "   ✗ is_test_extraction field not properly cast to boolean\n";
    }
    
    if (isset($casts['audio_quality_metrics']) && $casts['audio_quality_metrics'] === 'array') {
        echo "   ✓ audio_quality_metrics field properly cast to array\n";
    } else {
        echo "   ✗ audio_quality_metrics field not properly cast to array\n";
    }
    
} catch (Exception $e) {
    echo "   ✗ TranscriptionLog model check failed: " . $e->getMessage() . "\n";
}

// 3. Validate AudioExtractionTestJob
echo "\n3. Checking AudioExtractionTestJob class...\n";
try {
    if (class_exists('\App\Jobs\AudioExtractionTestJob')) {
        echo "   ✓ AudioExtractionTestJob class exists\n";
        
        $reflection = new ReflectionClass('\App\Jobs\AudioExtractionTestJob');
        if ($reflection->hasMethod('handle')) {
            echo "   ✓ AudioExtractionTestJob has handle method\n";
        } else {
            echo "   ✗ AudioExtractionTestJob missing handle method\n";
        }
    } else {
        echo "   ✗ AudioExtractionTestJob class not found\n";
    }
} catch (Exception $e) {
    echo "   ✗ AudioExtractionTestJob validation failed: " . $e->getMessage() . "\n";
}

// 4. Validate Controller Methods
echo "\n4. Checking TruefireCourseController methods...\n";
try {
    $controller = new \App\Http\Controllers\TruefireCourseController();
    $reflection = new ReflectionClass($controller);
    
    $requiredMethods = [
        'testAudioExtraction',
        'getAudioTestResults', 
        'getAudioTestHistory'
    ];
    
    foreach ($requiredMethods as $method) {
        if ($reflection->hasMethod($method)) {
            echo "   ✓ Method $method exists\n";
        } else {
            echo "   ✗ Method $method missing\n";
        }
    }
} catch (Exception $e) {
    echo "   ✗ Controller methods check failed: " . $e->getMessage() . "\n";
}

// 5. Validate Routes
echo "\n5. Checking routes registration...\n";
try {
    $routes = Route::getRoutes();
    $requiredRoutes = [
        'truefire-courses.test-audio-extraction',
        'truefire-courses.audio-test-results',
        'audio-test-history'
    ];
    
    foreach ($requiredRoutes as $routeName) {
        if ($routes->hasNamedRoute($routeName)) {
            echo "   ✓ Route $routeName registered\n";
        } else {
            echo "   ✗ Route $routeName not found\n";
        }
    }
} catch (Exception $e) {
    echo "   ✗ Routes validation failed: " . $e->getMessage() . "\n";
}

echo "\n=== Validation Complete ===\n";
echo "Phase 1 implementation validation finished.\n";
echo "If all checks show ✓, the foundation is ready for testing.\n\n";