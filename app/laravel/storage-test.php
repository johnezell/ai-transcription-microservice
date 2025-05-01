<?php

/**
 * Simple test script for directory deletion with Laravel's Storage facade
 * Run from within Laravel container with: php storage-test.php
 */

// Bootstrap Laravel application
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

// Test directory to delete
$jobId = 9999;
$jobPath = "s3/jobs/{$jobId}";

echo "Testing deletion of directory: {$jobPath}\n";

// Check if the directory exists
$exists = Storage::disk('public')->exists($jobPath);
echo "Directory exists before deletion: " . ($exists ? "Yes" : "No") . "\n";

if ($exists) {
    // List contents before deletion
    $contents = Storage::disk('public')->files($jobPath);
    echo "Directory contents before deletion: " . json_encode($contents) . "\n";
    
    // Delete the directory and all its contents
    $result = Storage::disk('public')->deleteDirectory($jobPath);
    echo "Deletion result: " . ($result ? "Success" : "Failed") . "\n";
    
    // Verify the directory no longer exists
    $exists = Storage::disk('public')->exists($jobPath);
    echo "Directory exists after deletion: " . ($exists ? "Yes (FAIL)" : "No (SUCCESS)") . "\n";
} else {
    echo "Test directory not found. Please create it first.\n";
} 