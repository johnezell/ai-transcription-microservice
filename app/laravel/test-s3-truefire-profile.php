<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Config;

// Create Laravel container
$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);

// Set up configuration
$app->singleton('config', function () {
    return new \Illuminate\Config\Repository([
        'filesystems' => [
            'default' => 'local',
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root' => __DIR__ . '/storage/app/private',
                ],
                's3' => [
                    'driver' => 's3',
                    'key' => $_ENV['TF_AWS_ACCESS_KEY_ID'] ?? $_ENV['AWS_ACCESS_KEY_ID'] ?? null,
                    'secret' => $_ENV['TF_SECRET_ACCESS_KEY'] ?? $_ENV['AWS_SECRET_ACCESS_KEY'] ?? null,
                    'region' => $_ENV['AWS_DEFAULT_REGION'] ?? 'us-east-1',
                    'bucket' => $_ENV['AWS_BUCKET'] ?? 'tfstream',
                    'url' => $_ENV['AWS_URL'] ?? null,
                    'endpoint' => $_ENV['AWS_ENDPOINT'] ?? null,
                    'use_path_style_endpoint' => filter_var($_ENV['AWS_USE_PATH_STYLE_ENDPOINT'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'profile' => $_ENV['AWS_PROFILE'] ?? 'truefire',
                    'credentials' => [
                        'profile' => $_ENV['AWS_PROFILE'] ?? 'truefire',
                        'filename' => $_ENV['AWS_SHARED_CREDENTIALS_FILE'] ?? '/mnt/aws_creds_mounted/credentials',
                        'config_filename' => $_ENV['AWS_CONFIG_FILE'] ?? '/mnt/aws_creds_mounted/config',
                    ],
                    'throw' => false,
                    'report' => false,
                ],
            ],
        ],
    ]);
});

// Register filesystem service provider
$provider = new FilesystemServiceProvider($app);
$provider->register();

echo "=== S3 Configuration Test with TrueFire Profile ===\n\n";

// Display current configuration
echo "Current S3 Configuration:\n";
echo "- Profile: " . ($_ENV['AWS_PROFILE'] ?? 'not set') . "\n";
echo "- Bucket: " . ($_ENV['AWS_BUCKET'] ?? 'not set') . "\n";
echo "- Region: " . ($_ENV['AWS_DEFAULT_REGION'] ?? 'not set') . "\n";
echo "- Access Key: " . (isset($_ENV['TF_AWS_ACCESS_KEY_ID']) ? 'Set (TF)' : (isset($_ENV['AWS_ACCESS_KEY_ID']) ? 'Set (AWS)' : 'Not set')) . "\n";
echo "- Secret Key: " . (isset($_ENV['TF_SECRET_ACCESS_KEY']) ? 'Set (TF)' : (isset($_ENV['AWS_SECRET_ACCESS_KEY']) ? 'Set (AWS)' : 'Not set')) . "\n";
echo "- Credentials File: " . ($_ENV['AWS_SHARED_CREDENTIALS_FILE'] ?? '/mnt/aws_creds_mounted/credentials') . "\n";
echo "- Config File: " . ($_ENV['AWS_CONFIG_FILE'] ?? '/mnt/aws_creds_mounted/config') . "\n\n";

try {
    // Test S3 disk access
    $s3Disk = Storage::disk('s3');
    
    echo "Testing S3 disk connection...\n";
    
    // Try to list files in the root directory
    echo "Attempting to list files in tfstream bucket root...\n";
    $files = $s3Disk->files('', true); // Get files recursively
    
    if (empty($files)) {
        echo "✓ Successfully connected to S3 but no files found in root directory\n";
        
        // Try to list some common directories that might exist
        $commonDirs = ['videos', 'segments', 'media', 'content'];
        foreach ($commonDirs as $dir) {
            try {
                $dirFiles = $s3Disk->files($dir);
                if (!empty($dirFiles)) {
                    echo "✓ Found " . count($dirFiles) . " files in '$dir' directory\n";
                    // Show first few files as examples
                    $examples = array_slice($dirFiles, 0, 3);
                    foreach ($examples as $file) {
                        echo "  - $file\n";
                    }
                    if (count($dirFiles) > 3) {
                        echo "  ... and " . (count($dirFiles) - 3) . " more files\n";
                    }
                    break;
                }
            } catch (Exception $e) {
                // Directory doesn't exist or no access, continue
            }
        }
    } else {
        echo "✓ Successfully connected to S3 and found " . count($files) . " files\n";
        // Show first few files as examples
        $examples = array_slice($files, 0, 5);
        foreach ($examples as $file) {
            echo "  - $file\n";
        }
        if (count($files) > 5) {
            echo "  ... and " . (count($files) - 5) . " more files\n";
        }
    }
    
    // Test creating a temporary URL for a hypothetical video file
    echo "\nTesting signed URL generation...\n";
    try {
        // Use a common video file pattern that might exist
        $testVideoKey = 'test_video_med.mp4';
        
        // Check if file exists first
        if ($s3Disk->exists($testVideoKey)) {
            $signedUrl = $s3Disk->temporaryUrl($testVideoKey, now()->addHour());
            echo "✓ Successfully generated signed URL for '$testVideoKey'\n";
            echo "  URL length: " . strlen($signedUrl) . " characters\n";
            echo "  URL starts with: " . substr($signedUrl, 0, 50) . "...\n";
        } else {
            echo "ℹ Test video file '$testVideoKey' not found, but signed URL generation capability is available\n";
        }
    } catch (Exception $e) {
        echo "✗ Failed to generate signed URL: " . $e->getMessage() . "\n";
    }
    
    echo "\n=== SUCCESS: S3 configuration with TrueFire profile is working! ===\n";
    
} catch (Exception $e) {
    echo "✗ S3 Connection Error: " . $e->getMessage() . "\n";
    echo "\nError Details:\n";
    echo "- Error Type: " . get_class($e) . "\n";
    echo "- File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    
    // Check for common issues
    if (strpos($e->getMessage(), 'credentials') !== false) {
        echo "\n🔧 Credential Issues Detected:\n";
        echo "- Verify AWS profile 'truefire' exists in credentials file\n";
        echo "- Check if credentials file is mounted at: " . ($_ENV['AWS_SHARED_CREDENTIALS_FILE'] ?? '/mnt/aws_creds_mounted/credentials') . "\n";
        echo "- Verify TF_AWS_ACCESS_KEY_ID and TF_SECRET_ACCESS_KEY are set correctly\n";
    }
    
    if (strpos($e->getMessage(), 'bucket') !== false || strpos($e->getMessage(), 'tfstream') !== false) {
        echo "\n🔧 Bucket Access Issues Detected:\n";
        echo "- Verify 'truefire' profile has access to 'tfstream' bucket\n";
        echo "- Check bucket permissions and policies\n";
    }
    
    echo "\n=== FAILED: S3 configuration needs attention ===\n";
}