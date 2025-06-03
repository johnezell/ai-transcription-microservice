<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Segment;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== S3 Signed URL Test ===\n\n";

try {
    // Test AWS credentials configuration
    echo "1. Testing AWS Configuration...\n";
    
    $s3Config = config('filesystems.disks.s3');
    echo "   AWS Profile: " . ($s3Config['profile'] ?? 'not set') . "\n";
    echo "   AWS Region: " . ($s3Config['region'] ?? 'not set') . "\n";
    echo "   AWS Bucket: " . ($s3Config['bucket'] ?? 'not set') . "\n";
    echo "   Has Access Key: " . (!empty($s3Config['key']) ? 'Yes' : 'No') . "\n";
    echo "   Has Secret Key: " . (!empty($s3Config['secret']) ? 'Yes' : 'No') . "\n";
    
    // Check credential files
    $credentialsFile = env('AWS_SHARED_CREDENTIALS_FILE', '/mnt/aws_creds_mounted/credentials');
    $configFile = env('AWS_CONFIG_FILE', '/mnt/aws_creds_mounted/config');
    
    echo "   Credentials File: " . $credentialsFile . " (" . (file_exists($credentialsFile) ? 'EXISTS' : 'NOT FOUND') . ")\n";
    echo "   Config File: " . $configFile . " (" . (file_exists($configFile) ? 'EXISTS' : 'NOT FOUND') . ")\n";
    
    echo "\n2. Testing S3 Disk Creation...\n";
    
    // Test creating S3 disk
    $s3Disk = Storage::disk('s3');
    echo "   S3 Disk created successfully\n";
    
    // Test creating tfstream disk
    $tfstreamConfig = $s3Config;
    $tfstreamConfig['bucket'] = 'tfstream';
    $tfstreamDisk = Storage::build($tfstreamConfig);
    echo "   TFStream S3 Disk created successfully\n";
    
    echo "\n3. Testing Segment Model...\n";
    
    // Create a mock segment for testing
    $mockSegment = new class extends Segment {
        public $id = 'test-segment-123';
        public $video = 'mp4:test-video-file';
        
        // Override database connection for testing
        public function getConnectionName()
        {
            return null; // Skip database operations
        }
    };
    
    echo "   Mock segment created with video: " . $mockSegment->video . "\n";
    
    echo "\n4. Testing Signed URL Generation...\n";
    
    try {
        $signedUrl = $mockSegment->getSignedUrl(3600); // 1 hour expiration
        echo "   ✓ Signed URL generated successfully!\n";
        echo "   URL length: " . strlen($signedUrl) . " characters\n";
        echo "   URL starts with: " . substr($signedUrl, 0, 50) . "...\n";
        
        // Validate URL structure
        if (strpos($signedUrl, 'tfstream') !== false) {
            echo "   ✓ URL contains tfstream bucket\n";
        } else {
            echo "   ⚠ URL does not contain tfstream bucket\n";
        }
        
        if (strpos($signedUrl, 'test-video-file_med.mp4') !== false) {
            echo "   ✓ URL contains expected S3 key\n";
        } else {
            echo "   ⚠ URL does not contain expected S3 key\n";
        }
        
        if (strpos($signedUrl, 'X-Amz-Signature') !== false) {
            echo "   ✓ URL is properly signed\n";
        } else {
            echo "   ⚠ URL does not appear to be signed\n";
        }
        
    } catch (Exception $e) {
        echo "   ✗ Failed to generate signed URL: " . $e->getMessage() . "\n";
        echo "   Error details: " . $e->getTraceAsString() . "\n";
    }
    
    echo "\n=== Test Complete ===\n";
    
} catch (Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
    echo "Error details: " . $e->getTraceAsString() . "\n";
}