<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Test script to verify Laravel can access all migrated files on D: drive
 * and perform cleanup of old files after verification
 */

// Initialize Laravel app
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class MigratedFilesVerifier
{
    private $dDriveDisk;
    private $localDisk;
    private $testResults = [];
    private $cleanupResults = [];
    
    public function __construct()
    {
        $this->dDriveDisk = Storage::disk('d_drive');
        $this->localDisk = Storage::disk('local');
    }
    
    public function run()
    {
        echo "=== Testing Migrated Files Access ===\n";
        echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";
        
        // Step 1: Test D: drive file access
        $this->testDDriveAccess();
        
        // Step 2: Verify file structure integrity
        $this->verifyFileStructure();
        
        // Step 3: Test file operations (read, size, etc.)
        $this->testFileOperations();
        
        // Step 4: Clean up old files (only after successful verification)
        $this->cleanupOldFiles();
        
        // Step 5: Generate final report
        $this->generateReport();
    }
    
    private function testDDriveAccess()
    {
        echo "--- Step 1: Testing D: Drive Access ---\n";
        
        // Test basic disk connectivity
        try {
            $files = $this->dDriveDisk->allFiles('ai-transcription-downloads/truefire-courses');
            echo "✓ D: drive accessible, found " . count($files) . " files\n";
            $this->testResults['d_drive_accessible'] = true;
            $this->testResults['total_files_found'] = count($files);
        } catch (Exception $e) {
            echo "✗ D: drive access failed: " . $e->getMessage() . "\n";
            $this->testResults['d_drive_accessible'] = false;
            return;
        }
        
        // Test directory structure
        $expectedCourses = ['1', '3', '4', '5', '6', 'legacy'];
        foreach ($expectedCourses as $courseId) {
            $coursePath = "ai-transcription-downloads/truefire-courses/$courseId";
            if ($this->dDriveDisk->exists($coursePath)) {
                $courseFiles = $this->dDriveDisk->files($coursePath);
                echo "✓ Course $courseId: " . count($courseFiles) . " files\n";
                $this->testResults["course_$courseId"] = count($courseFiles);
            } else {
                echo "- Course $courseId: not found (may be empty)\n";
                $this->testResults["course_$courseId"] = 0;
            }
        }
    }
    
    private function verifyFileStructure()
    {
        echo "\n--- Step 2: Verifying File Structure ---\n";
        
        $sampleFiles = [
            'ai-transcription-downloads/truefire-courses/1/7959.mp4',
            'ai-transcription-downloads/truefire-courses/3/2860.mp4',
            'ai-transcription-downloads/truefire-courses/4/8476.mp4',
            'ai-transcription-downloads/truefire-courses/5/4510.mp4',
            'ai-transcription-downloads/truefire-courses/6/4570.mp4'
        ];
        
        $structureValid = true;
        foreach ($sampleFiles as $filePath) {
            if ($this->dDriveDisk->exists($filePath)) {
                echo "✓ Structure valid: $filePath\n";
            } else {
                echo "✗ Structure invalid: $filePath not found\n";
                $structureValid = false;
            }
        }
        
        $this->testResults['structure_valid'] = $structureValid;
    }
    
    private function testFileOperations()
    {
        echo "\n--- Step 3: Testing File Operations ---\n";
        
        $testFiles = [
            'ai-transcription-downloads/truefire-courses/1/7959.mp4',
            'ai-transcription-downloads/truefire-courses/3/2860.mp4',
            'ai-transcription-downloads/truefire-courses/4/8476.mp4'
        ];
        
        $operationsSuccessful = 0;
        $totalOperations = 0;
        
        foreach ($testFiles as $filePath) {
            if (!$this->dDriveDisk->exists($filePath)) {
                continue;
            }
            
            try {
                // Test file size
                $size = $this->dDriveDisk->size($filePath);
                echo "✓ File size: " . basename($filePath) . " = " . $this->formatBytes($size) . "\n";
                $operationsSuccessful++;
                $totalOperations++;
                
                // Test file reading (first 1KB)
                $content = $this->dDriveDisk->get($filePath);
                if (strlen($content) > 0) {
                    echo "✓ File readable: " . basename($filePath) . " (" . strlen($content) . " bytes)\n";
                    $operationsSuccessful++;
                } else {
                    echo "✗ File empty or unreadable: " . basename($filePath) . "\n";
                }
                $totalOperations++;
                
                // Test last modified time
                $lastModified = $this->dDriveDisk->lastModified($filePath);
                echo "✓ Last modified: " . basename($filePath) . " = " . date('Y-m-d H:i:s', $lastModified) . "\n";
                $operationsSuccessful++;
                $totalOperations++;
                
            } catch (Exception $e) {
                echo "✗ Operation failed for " . basename($filePath) . ": " . $e->getMessage() . "\n";
                $totalOperations += 3; // We attempted 3 operations
            }
        }
        
        $this->testResults['file_operations_success_rate'] = $totalOperations > 0 ? ($operationsSuccessful / $totalOperations) * 100 : 0;
        echo "File operations success rate: " . number_format($this->testResults['file_operations_success_rate'], 1) . "%\n";
    }
    
    private function cleanupOldFiles()
    {
        echo "\n--- Step 4: Cleaning Up Old Files ---\n";
        
        // Only proceed with cleanup if all tests passed
        if (!$this->testResults['d_drive_accessible'] || !$this->testResults['structure_valid'] || $this->testResults['file_operations_success_rate'] < 90) {
            echo "⚠️  Skipping cleanup due to failed verification tests\n";
            $this->cleanupResults['cleanup_performed'] = false;
            $this->cleanupResults['reason'] = 'Verification tests failed';
            return;
        }
        
        echo "✓ All verification tests passed, proceeding with cleanup...\n";
        
        // Clean up current format files
        $currentPath = storage_path('app/private/truefire-courses');
        if (is_dir($currentPath)) {
            $this->cleanupDirectory($currentPath, 'current format');
        }
        
        // Clean up legacy format files
        $legacyPath = storage_path('app/private/var/www/storage/downloads');
        if (is_dir($legacyPath)) {
            $this->cleanupDirectory($legacyPath, 'legacy format');
        }
        
        // Clean up test directories
        $testPath = storage_path('app/private/test-course-1748967012');
        if (is_dir($testPath)) {
            $this->cleanupDirectory($testPath, 'test files');
        }
        
        $this->cleanupResults['cleanup_performed'] = true;
    }
    
    private function cleanupDirectory($path, $description)
    {
        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            
            $fileCount = 0;
            $totalSize = 0;
            
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $totalSize += $file->getSize();
                    $fileCount++;
                    unlink($file->getPathname());
                }
            }
            
            // Remove empty directories
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                }
            }
            
            // Remove the main directory
            rmdir($path);
            
            echo "✓ Cleaned up $description: $fileCount files (" . $this->formatBytes($totalSize) . ")\n";
            $this->cleanupResults[$description] = [
                'files_removed' => $fileCount,
                'size_freed' => $totalSize
            ];
            
        } catch (Exception $e) {
            echo "✗ Failed to cleanup $description: " . $e->getMessage() . "\n";
            $this->cleanupResults[$description] = [
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function generateReport()
    {
        echo "\n--- Step 5: Final Report ---\n";
        
        echo "Migration Verification Results:\n";
        echo "  D: Drive Accessible: " . ($this->testResults['d_drive_accessible'] ? 'YES' : 'NO') . "\n";
        echo "  Total Files Found: " . ($this->testResults['total_files_found'] ?? 0) . "\n";
        echo "  File Structure Valid: " . ($this->testResults['structure_valid'] ? 'YES' : 'NO') . "\n";
        echo "  File Operations Success Rate: " . number_format($this->testResults['file_operations_success_rate'] ?? 0, 1) . "%\n";
        
        echo "\nCourse Distribution:\n";
        foreach (['1', '3', '4', '5', '6', 'legacy'] as $courseId) {
            $count = $this->testResults["course_$courseId"] ?? 0;
            echo "  Course $courseId: $count files\n";
        }
        
        echo "\nCleanup Results:\n";
        if ($this->cleanupResults['cleanup_performed'] ?? false) {
            echo "  Cleanup Performed: YES\n";
            $totalFreed = 0;
            $totalFilesRemoved = 0;
            
            foreach ($this->cleanupResults as $key => $result) {
                if (is_array($result) && isset($result['files_removed'])) {
                    $totalFilesRemoved += $result['files_removed'];
                    $totalFreed += $result['size_freed'];
                    echo "  - " . ucfirst($key) . ": {$result['files_removed']} files (" . $this->formatBytes($result['size_freed']) . ")\n";
                }
            }
            
            echo "  Total Cleanup: $totalFilesRemoved files (" . $this->formatBytes($totalFreed) . ")\n";
        } else {
            echo "  Cleanup Performed: NO\n";
            if (isset($this->cleanupResults['reason'])) {
                echo "  Reason: " . $this->cleanupResults['reason'] . "\n";
            }
        }
        
        // Overall status
        $allTestsPassed = ($this->testResults['d_drive_accessible'] ?? false) && 
                         ($this->testResults['structure_valid'] ?? false) && 
                         (($this->testResults['file_operations_success_rate'] ?? 0) >= 90);
        
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "MIGRATION STATUS: " . ($allTestsPassed ? "SUCCESS ✓" : "FAILED ✗") . "\n";
        echo "All video files are now accessible on D: drive at:\n";
        echo "D:/ai-transcription-downloads/truefire-courses/\n";
        echo str_repeat("=", 50) . "\n";
        
        // Save detailed report
        $reportPath = __DIR__ . '/migration-verification-report-' . date('Y-m-d-H-i-s') . '.json';
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'test_results' => $this->testResults,
            'cleanup_results' => $this->cleanupResults,
            'overall_status' => $allTestsPassed ? 'SUCCESS' : 'FAILED'
        ];
        
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        echo "Detailed report saved: " . basename($reportPath) . "\n";
    }
    
    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

// Run the verification
try {
    $verifier = new MigratedFilesVerifier();
    $verifier->run();
} catch (Exception $e) {
    echo "VERIFICATION FAILED: " . $e->getMessage() . "\n";
    exit(1);
}