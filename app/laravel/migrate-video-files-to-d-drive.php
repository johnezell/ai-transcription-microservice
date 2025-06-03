<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Video File Migration Script
 * 
 * Migrates all existing downloaded video files from local storage to D: drive
 * Preserves directory structure and provides comprehensive logging
 */
class VideoFileMigrator
{
    private $logFile;
    private $migrationLog = [];
    private $errors = [];
    private $stats = [
        'total_files' => 0,
        'migrated_files' => 0,
        'skipped_files' => 0,
        'failed_files' => 0,
        'total_size_bytes' => 0,
        'migrated_size_bytes' => 0
    ];

    // Source paths (old locations)
    private $sourcePaths = [
        'current' => 'storage/app/private/truefire-courses',
        'legacy' => 'storage/app/private/var/www/storage/downloads'
    ];

    // Target path on D: drive
    private $targetBasePath = '/mnt/d_drive/ai-transcription-downloads/truefire-courses';

    public function __construct()
    {
        $this->logFile = __DIR__ . '/migration-log-' . date('Y-m-d-H-i-s') . '.log';
        $this->log("=== Video File Migration Started at " . date('Y-m-d H:i:s') . " ===");
    }

    public function run()
    {
        try {
            $this->log("Starting video file migration process...");
            
            // Step 1: Analyze existing files
            $this->analyzeExistingFiles();
            
            // Step 2: Verify D: drive accessibility
            $this->verifyDDriveAccess();
            
            // Step 3: Create backup manifest
            $this->createBackupManifest();
            
            // Step 4: Migrate files
            $this->migrateFiles();
            
            // Step 5: Verify migration
            $this->verifyMigration();
            
            // Step 6: Generate final report
            $this->generateFinalReport();
            
        } catch (Exception $e) {
            $this->log("CRITICAL ERROR: " . $e->getMessage());
            $this->log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    private function analyzeExistingFiles()
    {
        $this->log("\n--- Step 1: Analyzing Existing Files ---");
        
        // Analyze current format files
        $currentPath = __DIR__ . '/' . $this->sourcePaths['current'];
        if (is_dir($currentPath)) {
            $this->log("Scanning current format files in: $currentPath");
            $this->scanDirectory($currentPath, 'current');
        } else {
            $this->log("Current format directory not found: $currentPath");
        }
        
        // Analyze legacy format files
        $legacyPath = __DIR__ . '/' . $this->sourcePaths['legacy'];
        if (is_dir($legacyPath)) {
            $this->log("Scanning legacy format files in: $legacyPath");
            $this->scanDirectory($legacyPath, 'legacy');
        } else {
            $this->log("Legacy format directory not found: $legacyPath");
        }
        
        $this->log("Analysis complete. Found {$this->stats['total_files']} files totaling " . 
                  $this->formatBytes($this->stats['total_size_bytes']));
    }

    private function scanDirectory($path, $type)
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'mp4') {
                $relativePath = str_replace($path . '/', '', $file->getPathname());
                $fileSize = $file->getSize();
                
                $this->migrationLog[] = [
                    'source_type' => $type,
                    'source_path' => $file->getPathname(),
                    'relative_path' => $relativePath,
                    'file_size' => $fileSize,
                    'checksum' => md5_file($file->getPathname()),
                    'status' => 'pending'
                ];
                
                $this->stats['total_files']++;
                $this->stats['total_size_bytes'] += $fileSize;
                
                $this->log("Found: $relativePath (" . $this->formatBytes($fileSize) . ")");
            }
        }
    }

    private function verifyDDriveAccess()
    {
        $this->log("\n--- Step 2: Verifying D: Drive Access ---");
        
        // Check if D: drive mount point exists
        if (!is_dir('/mnt/d_drive')) {
            throw new Exception("D: drive mount point not found at /mnt/d_drive");
        }
        
        // Test write permissions
        $testFile = '/mnt/d_drive/migration-test-' . uniqid() . '.tmp';
        if (!file_put_contents($testFile, 'test')) {
            throw new Exception("Cannot write to D: drive. Check permissions.");
        }
        unlink($testFile);
        
        // Create target directory structure
        $targetDir = $this->targetBasePath;
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                throw new Exception("Failed to create target directory: $targetDir");
            }
            $this->log("Created target directory: $targetDir");
        }
        
        $this->log("D: drive access verified successfully");
    }

    private function createBackupManifest()
    {
        $this->log("\n--- Step 3: Creating Backup Manifest ---");
        
        $manifestPath = __DIR__ . '/migration-manifest-' . date('Y-m-d-H-i-s') . '.json';
        $manifest = [
            'created_at' => date('Y-m-d H:i:s'),
            'source_paths' => $this->sourcePaths,
            'target_path' => $this->targetBasePath,
            'files' => $this->migrationLog,
            'stats' => $this->stats
        ];
        
        if (!file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT))) {
            throw new Exception("Failed to create backup manifest");
        }
        
        $this->log("Backup manifest created: $manifestPath");
    }

    private function migrateFiles()
    {
        $this->log("\n--- Step 4: Migrating Files ---");
        
        foreach ($this->migrationLog as &$fileInfo) {
            try {
                $this->migrateFile($fileInfo);
            } catch (Exception $e) {
                $this->errors[] = "Failed to migrate {$fileInfo['relative_path']}: " . $e->getMessage();
                $fileInfo['status'] = 'failed';
                $fileInfo['error'] = $e->getMessage();
                $this->stats['failed_files']++;
                $this->log("ERROR: Failed to migrate {$fileInfo['relative_path']}: " . $e->getMessage());
            }
        }
    }

    private function migrateFile(&$fileInfo)
    {
        $sourcePath = $fileInfo['source_path'];
        
        // Determine target path based on source type
        if ($fileInfo['source_type'] === 'current') {
            // Current format: truefire-courses/{courseId}/{segmentId}.mp4
            $targetPath = $this->targetBasePath . '/' . $fileInfo['relative_path'];
        } else {
            // Legacy format: segment-{segmentId}.mp4 -> need to determine course ID
            $segmentId = $this->extractSegmentIdFromLegacyFile($fileInfo['relative_path']);
            $courseId = $this->determineCourseIdForSegment($segmentId);
            $targetPath = $this->targetBasePath . "/$courseId/$segmentId.mp4";
        }
        
        // Create target directory if it doesn't exist
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                throw new Exception("Failed to create directory: $targetDir");
            }
        }
        
        // Check if file already exists at target
        if (file_exists($targetPath)) {
            $existingChecksum = md5_file($targetPath);
            if ($existingChecksum === $fileInfo['checksum']) {
                $this->log("SKIP: File already exists with same checksum: " . basename($targetPath));
                $fileInfo['status'] = 'skipped';
                $this->stats['skipped_files']++;
                return;
            } else {
                $this->log("WARNING: File exists but checksum differs, overwriting: " . basename($targetPath));
            }
        }
        
        // Copy file
        if (!copy($sourcePath, $targetPath)) {
            throw new Exception("Failed to copy file from $sourcePath to $targetPath");
        }
        
        // Verify copied file
        $targetChecksum = md5_file($targetPath);
        if ($targetChecksum !== $fileInfo['checksum']) {
            unlink($targetPath); // Remove corrupted file
            throw new Exception("Checksum mismatch after copy. Expected: {$fileInfo['checksum']}, Got: $targetChecksum");
        }
        
        $fileInfo['status'] = 'migrated';
        $fileInfo['target_path'] = $targetPath;
        $this->stats['migrated_files']++;
        $this->stats['migrated_size_bytes'] += $fileInfo['file_size'];
        
        $this->log("MIGRATED: " . basename($sourcePath) . " -> " . $targetPath);
    }

    private function extractSegmentIdFromLegacyFile($filename)
    {
        // Extract segment ID from "segment-{id}.mp4"
        if (preg_match('/segment-(\d+)\.mp4$/', $filename, $matches)) {
            return $matches[1];
        }
        throw new Exception("Cannot extract segment ID from legacy filename: $filename");
    }

    private function determineCourseIdForSegment($segmentId)
    {
        // Check if this segment exists in current format to determine course ID
        $currentBasePath = __DIR__ . '/' . $this->sourcePaths['current'];
        
        if (is_dir($currentBasePath)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($currentBasePath, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() === "$segmentId.mp4") {
                    // Extract course ID from path
                    $relativePath = str_replace($currentBasePath . '/', '', $file->getPath());
                    return $relativePath; // This should be the course ID
                }
            }
        }
        
        // If not found in current format, use a default course ID for legacy files
        $this->log("WARNING: Could not determine course ID for segment $segmentId, using 'legacy'");
        return 'legacy';
    }

    private function verifyMigration()
    {
        $this->log("\n--- Step 5: Verifying Migration ---");
        
        $verificationErrors = [];
        
        foreach ($this->migrationLog as $fileInfo) {
            if ($fileInfo['status'] === 'migrated') {
                $targetPath = $fileInfo['target_path'];
                
                // Check if file exists
                if (!file_exists($targetPath)) {
                    $verificationErrors[] = "Migrated file not found: $targetPath";
                    continue;
                }
                
                // Check file size
                $targetSize = filesize($targetPath);
                if ($targetSize !== $fileInfo['file_size']) {
                    $verificationErrors[] = "Size mismatch for $targetPath. Expected: {$fileInfo['file_size']}, Got: $targetSize";
                    continue;
                }
                
                // Check checksum
                $targetChecksum = md5_file($targetPath);
                if ($targetChecksum !== $fileInfo['checksum']) {
                    $verificationErrors[] = "Checksum mismatch for $targetPath. Expected: {$fileInfo['checksum']}, Got: $targetChecksum";
                    continue;
                }
                
                $this->log("VERIFIED: " . basename($targetPath));
            }
        }
        
        if (!empty($verificationErrors)) {
            $this->log("VERIFICATION ERRORS:");
            foreach ($verificationErrors as $error) {
                $this->log("  - $error");
            }
            throw new Exception("Migration verification failed with " . count($verificationErrors) . " errors");
        }
        
        $this->log("All migrated files verified successfully");
    }

    private function generateFinalReport()
    {
        $this->log("\n--- Step 6: Final Migration Report ---");
        
        $this->log("Migration Statistics:");
        $this->log("  Total files found: {$this->stats['total_files']}");
        $this->log("  Files migrated: {$this->stats['migrated_files']}");
        $this->log("  Files skipped: {$this->stats['skipped_files']}");
        $this->log("  Files failed: {$this->stats['failed_files']}");
        $this->log("  Total size: " . $this->formatBytes($this->stats['total_size_bytes']));
        $this->log("  Migrated size: " . $this->formatBytes($this->stats['migrated_size_bytes']));
        
        if (!empty($this->errors)) {
            $this->log("\nErrors encountered:");
            foreach ($this->errors as $error) {
                $this->log("  - $error");
            }
        }
        
        // Test Laravel Storage access
        $this->testLaravelStorageAccess();
        
        $this->log("\n=== Migration completed at " . date('Y-m-d H:i:s') . " ===");
        
        // Save final migration log
        $finalLogPath = __DIR__ . '/migration-final-log-' . date('Y-m-d-H-i-s') . '.json';
        $finalLog = [
            'completed_at' => date('Y-m-d H:i:s'),
            'stats' => $this->stats,
            'files' => $this->migrationLog,
            'errors' => $this->errors
        ];
        file_put_contents($finalLogPath, json_encode($finalLog, JSON_PRETTY_PRINT));
        $this->log("Final migration log saved: $finalLogPath");
    }

    private function testLaravelStorageAccess()
    {
        $this->log("\n--- Testing Laravel Storage Access ---");
        
        try {
            // Initialize Laravel app for testing
            $app = require_once __DIR__ . '/bootstrap/app.php';
            $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
            
            // Test d_drive disk access
            $disk = Storage::disk('d_drive');
            
            // Test file existence for a few migrated files
            $testCount = 0;
            $successCount = 0;
            
            foreach ($this->migrationLog as $fileInfo) {
                if ($fileInfo['status'] === 'migrated' && $testCount < 5) {
                    $testCount++;
                    
                    // Convert absolute path to relative path for Storage disk
                    $relativePath = str_replace('/mnt/d_drive/', '', $fileInfo['target_path']);
                    
                    if ($disk->exists($relativePath)) {
                        $successCount++;
                        $this->log("✓ Laravel can access: $relativePath");
                    } else {
                        $this->log("✗ Laravel cannot access: $relativePath");
                    }
                }
            }
            
            $this->log("Laravel Storage test: $successCount/$testCount files accessible");
            
        } catch (Exception $e) {
            $this->log("Laravel Storage test failed: " . $e->getMessage());
        }
    }

    private function log($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";
        
        echo $logMessage;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    public function rollback($manifestFile)
    {
        $this->log("\n=== ROLLBACK INITIATED ===");
        
        if (!file_exists($manifestFile)) {
            throw new Exception("Manifest file not found: $manifestFile");
        }
        
        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (!$manifest) {
            throw new Exception("Invalid manifest file");
        }
        
        $this->log("Rolling back migration from: " . $manifest['created_at']);
        
        foreach ($manifest['files'] as $fileInfo) {
            if (isset($fileInfo['target_path']) && file_exists($fileInfo['target_path'])) {
                if (unlink($fileInfo['target_path'])) {
                    $this->log("Removed: " . $fileInfo['target_path']);
                } else {
                    $this->log("Failed to remove: " . $fileInfo['target_path']);
                }
            }
        }
        
        $this->log("Rollback completed");
    }
}

// Main execution
try {
    $migrator = new VideoFileMigrator();
    
    // Check for rollback command
    if (isset($argv[1]) && $argv[1] === 'rollback' && isset($argv[2])) {
        $migrator->rollback($argv[2]);
    } else {
        $migrator->run();
    }
    
} catch (Exception $e) {
    echo "MIGRATION FAILED: " . $e->getMessage() . "\n";
    echo "Check the log files for detailed information.\n";
    exit(1);
}