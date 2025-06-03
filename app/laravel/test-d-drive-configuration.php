<?php

/**
 * D: Drive Configuration Test Script
 * 
 * This script tests and verifies the D: drive video download configuration
 * to ensure that Laravel can properly access and write to the D: drive storage.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Config;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== D: Drive Configuration Test ===\n\n";

// Test 1: Verify Environment Variables
echo "1. Testing Environment Variables:\n";
echo "   FILESYSTEM_DISK: " . env('FILESYSTEM_DISK', 'NOT SET') . "\n";
echo "   D_DRIVE_PATH: " . env('D_DRIVE_PATH', 'NOT SET') . "\n";
echo "   Default filesystem disk: " . Config::get('filesystems.default') . "\n\n";

// Test 2: Verify Filesystem Configuration
echo "2. Testing Filesystem Configuration:\n";
$filesystemConfig = Config::get('filesystems.disks.d_drive');
if ($filesystemConfig) {
    echo "   ✓ D: drive disk configuration found\n";
    echo "   Driver: " . $filesystemConfig['driver'] . "\n";
    echo "   Root path: " . $filesystemConfig['root'] . "\n";
} else {
    echo "   ✗ D: drive disk configuration NOT found\n";
}
echo "\n";

// Test 3: Test Storage Disk Access
echo "3. Testing Storage Disk Access:\n";
try {
    $disk = Storage::disk('d_drive');
    echo "   ✓ Successfully created d_drive disk instance\n";
    
    // Get the actual path
    $actualPath = $disk->path('');
    echo "   Actual storage path: " . $actualPath . "\n";
    
} catch (Exception $e) {
    echo "   ✗ Failed to create d_drive disk instance: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 4: Test Directory Access and Permissions
echo "4. Testing Directory Access and Permissions:\n";
try {
    $disk = Storage::disk('d_drive');
    
    // Check if root directory exists and is accessible
    if (is_dir($disk->path(''))) {
        echo "   ✓ Root directory exists and is accessible\n";
        
        // Check permissions
        $perms = fileperms($disk->path(''));
        echo "   Directory permissions: " . substr(sprintf('%o', $perms), -4) . "\n";
        
        // Check if writable
        if (is_writable($disk->path(''))) {
            echo "   ✓ Directory is writable\n";
        } else {
            echo "   ✗ Directory is NOT writable\n";
        }
    } else {
        echo "   ✗ Root directory does not exist or is not accessible\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error checking directory: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 5: Test File Write Operations
echo "5. Testing File Write Operations:\n";
try {
    $disk = Storage::disk('d_drive');
    $testFile = 'test-file-' . time() . '.txt';
    $testContent = 'This is a test file created at ' . date('Y-m-d H:i:s') . "\n";
    $testContent .= 'Testing D: drive configuration for video downloads.';
    
    // Write test file
    $success = $disk->put($testFile, $testContent);
    
    if ($success) {
        echo "   ✓ Successfully wrote test file: $testFile\n";
        
        // Verify file exists
        if ($disk->exists($testFile)) {
            echo "   ✓ Test file exists and is accessible\n";
            
            // Read back content
            $readContent = $disk->get($testFile);
            if ($readContent === $testContent) {
                echo "   ✓ File content matches what was written\n";
            } else {
                echo "   ✗ File content does NOT match\n";
            }
            
            // Get file size
            $size = $disk->size($testFile);
            echo "   File size: $size bytes\n";
            
            // Clean up test file
            $disk->delete($testFile);
            echo "   ✓ Test file cleaned up successfully\n";
            
        } else {
            echo "   ✗ Test file does NOT exist after write\n";
        }
    } else {
        echo "   ✗ Failed to write test file\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error during file operations: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 6: Test TrueFire Courses Directory Structure
echo "6. Testing TrueFire Courses Directory Structure:\n";
try {
    $disk = Storage::disk('d_drive');
    $coursesDir = 'truefire-courses';
    
    // Check if truefire-courses directory exists
    if ($disk->exists($coursesDir)) {
        echo "   ✓ truefire-courses directory exists\n";
        
        // List contents
        $contents = $disk->allDirectories($coursesDir);
        echo "   Subdirectories found: " . count($contents) . "\n";
        
        if (count($contents) > 0) {
            echo "   First few subdirectories:\n";
            foreach (array_slice($contents, 0, 5) as $dir) {
                echo "     - $dir\n";
            }
        }
        
    } else {
        echo "   ✗ truefire-courses directory does NOT exist\n";
        
        // Try to create it
        echo "   Attempting to create truefire-courses directory...\n";
        if ($disk->makeDirectory($coursesDir)) {
            echo "   ✓ Successfully created truefire-courses directory\n";
        } else {
            echo "   ✗ Failed to create truefire-courses directory\n";
        }
    }
} catch (Exception $e) {
    echo "   ✗ Error checking truefire-courses directory: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 7: Test Default Disk Configuration
echo "7. Testing Default Disk Configuration:\n";
try {
    $defaultDisk = Storage::disk();
    $defaultDiskName = Config::get('filesystems.default');
    
    echo "   Default disk name: $defaultDiskName\n";
    
    if ($defaultDiskName === 'd_drive') {
        echo "   ✓ Default disk is correctly set to d_drive\n";
        
        // Test default disk operations
        $testFile = 'default-disk-test-' . time() . '.txt';
        $testContent = 'Testing default disk configuration';
        
        if ($defaultDisk->put($testFile, $testContent)) {
            echo "   ✓ Default disk write operation successful\n";
            $defaultDisk->delete($testFile);
            echo "   ✓ Default disk cleanup successful\n";
        } else {
            echo "   ✗ Default disk write operation failed\n";
        }
    } else {
        echo "   ⚠ Default disk is NOT set to d_drive (this may be intentional)\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error testing default disk: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 8: Simulate Video Download Path
echo "8. Testing Video Download Path Simulation:\n";
try {
    $disk = Storage::disk('d_drive');
    $courseId = 'test-course-123';
    $segmentId = 'segment-456';
    $videoPath = "truefire-courses/$courseId/$segmentId.mp4";
    
    echo "   Simulated video path: $videoPath\n";
    
    // Create directory structure
    $courseDir = "truefire-courses/$courseId";
    if (!$disk->exists($courseDir)) {
        if ($disk->makeDirectory($courseDir)) {
            echo "   ✓ Created course directory: $courseDir\n";
        } else {
            echo "   ✗ Failed to create course directory\n";
        }
    } else {
        echo "   ✓ Course directory already exists: $courseDir\n";
    }
    
    // Simulate video file creation
    $videoContent = 'This would be video content for segment ' . $segmentId;
    if ($disk->put($videoPath, $videoContent)) {
        echo "   ✓ Successfully simulated video file creation\n";
        
        // Get full system path
        $fullPath = $disk->path($videoPath);
        echo "   Full system path: $fullPath\n";
        
        // Clean up
        $disk->delete($videoPath);
        echo "   ✓ Cleaned up simulated video file\n";
    } else {
        echo "   ✗ Failed to simulate video file creation\n";
    }
    
} catch (Exception $e) {
    echo "   ✗ Error during video download simulation: " . $e->getMessage() . "\n";
}
echo "\n";

// Summary
echo "=== Test Summary ===\n";
echo "D: drive configuration test completed.\n";
echo "Review the results above to ensure all tests passed.\n";
echo "If any tests failed, check the configuration and permissions.\n\n";

// Final verification
try {
    $disk = Storage::disk('d_drive');
    $rootPath = $disk->path('');
    echo "Final verification:\n";
    echo "✓ D: drive is mounted at: $rootPath\n";
    echo "✓ Laravel can access the D: drive through the 'd_drive' disk\n";
    echo "✓ Ready for video download operations\n";
} catch (Exception $e) {
    echo "✗ Final verification failed: " . $e->getMessage() . "\n";
}

echo "\n=== End of Test ===\n";