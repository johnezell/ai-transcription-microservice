<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Storage;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Cleaning up test directories...\n";

try {
    // Clean up test directories
    $testDirs = [
        'truefire-courses/test-course-123',
        'truefire-courses/test-course-1748973906'
    ];
    
    foreach ($testDirs as $dir) {
        if (Storage::exists($dir)) {
            Storage::deleteDirectory($dir);
            echo "✓ Deleted: $dir\n";
        } else {
            echo "- Not found: $dir\n";
        }
    }
    
    echo "Cleanup completed successfully.\n";
    
} catch (Exception $e) {
    echo "Error during cleanup: " . $e->getMessage() . "\n";
}