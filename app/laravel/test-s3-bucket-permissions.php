<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== S3 Bucket Permissions Test ===\n\n";

// List of profiles to test
$profiles = [
    'tfs-shared-services',
    'truefire', 
    'tfs-services',
    'tfs-contractor',
    'tfs-dev'
];

$buckets = [
    'tfstream',
    'aws-transcription-data-542876199144-us-east-1'
];

foreach ($profiles as $profile) {
    echo "Testing profile: {$profile}\n";
    echo str_repeat('-', 40) . "\n";
    
    try {
        // Create S3 client with specific profile
        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'profile' => $profile,
            'credentials' => [
                'profile' => $profile,
            ],
            'use_path_style_endpoint' => false,
        ]);
        
        foreach ($buckets as $bucket) {
            echo "  Testing bucket: {$bucket}\n";
            
            try {
                // Test bucket access
                $result = $s3Client->headBucket(['Bucket' => $bucket]);
                echo "    ✓ Bucket accessible\n";
                
                // Test listing objects
                try {
                    $objects = $s3Client->listObjectsV2([
                        'Bucket' => $bucket,
                        'MaxKeys' => 5
                    ]);
                    
                    $count = $objects['KeyCount'] ?? 0;
                    echo "    ✓ Can list objects ({$count} found)\n";
                    
                    if ($count > 0 && isset($objects['Contents'])) {
                        echo "    Sample files:\n";
                        foreach (array_slice($objects['Contents'], 0, 3) as $object) {
                            echo "      - " . $object['Key'] . "\n";
                        }
                    }
                    
                } catch (AwsException $e) {
                    echo "    ⚠ Cannot list objects: " . $e->getAwsErrorMessage() . "\n";
                }
                
            } catch (AwsException $e) {
                $errorCode = $e->getAwsErrorCode();
                echo "    ✗ Bucket access failed: {$errorCode} - " . $e->getAwsErrorMessage() . "\n";
            }
        }
        
        echo "\n";
        
    } catch (Exception $e) {
        echo "  ✗ Profile setup failed: " . $e->getMessage() . "\n\n";
    }
}

echo "=== Testing Current Application Configuration ===\n";

try {
    $s3Config = config('filesystems.disks.s3');
    echo "Current S3 Configuration:\n";
    echo "  Profile: " . ($s3Config['profile'] ?? 'not set') . "\n";
    echo "  Region: " . ($s3Config['region'] ?? 'not set') . "\n";
    echo "  Bucket: " . ($s3Config['bucket'] ?? 'not set') . "\n";
    
    // Test current configuration
    $s3Disk = Storage::disk('s3');
    echo "\n  Testing current S3 disk configuration...\n";
    
    try {
        // Try to list files in the configured bucket
        $files = $s3Disk->files('');
        echo "  ✓ Current configuration works - found " . count($files) . " files\n";
        
        if (count($files) > 0) {
            echo "  Sample files from current bucket:\n";
            foreach (array_slice($files, 0, 3) as $file) {
                echo "    - {$file}\n";
            }
        }
        
    } catch (Exception $e) {
        echo "  ✗ Current configuration failed: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "✗ Configuration test failed: " . $e->getMessage() . "\n";
}

echo "\n=== Testing TFStream Bucket Specifically ===\n";

try {
    // Create a disk specifically for tfstream bucket
    $tfstreamConfig = config('filesystems.disks.s3');
    $tfstreamConfig['bucket'] = 'tfstream';
    
    $tfstreamDisk = Storage::build($tfstreamConfig);
    
    echo "Testing tfstream bucket with current profile ({$tfstreamConfig['profile']})...\n";
    
    try {
        $tfstreamFiles = $tfstreamDisk->files('');
        echo "✓ TFStream bucket accessible - found " . count($tfstreamFiles) . " files\n";
        
        if (count($tfstreamFiles) > 0) {
            echo "Sample files from tfstream:\n";
            foreach (array_slice($tfstreamFiles, 0, 5) as $file) {
                echo "  - {$file}\n";
            }
        }
        
    } catch (Exception $e) {
        echo "✗ TFStream bucket access failed: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "✗ TFStream test setup failed: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";