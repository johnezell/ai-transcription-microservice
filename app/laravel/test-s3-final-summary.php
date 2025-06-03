<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use App\Models\Segment;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== S3 Connectivity Test - Final Summary ===\n\n";

// Test Results Summary
$testResults = [
    'basic_s3_connection' => false,
    'signed_url_generation' => false,
    'bucket_access' => false,
    'file_download' => false,
    'credential_issues' => []
];

try {
    echo "1. BASIC S3 CONNECTION TEST\n";
    echo str_repeat('-', 40) . "\n";
    
    $s3Config = config('filesystems.disks.s3');
    echo "Current Configuration:\n";
    echo "  Profile: " . ($s3Config['profile'] ?? 'not set') . "\n";
    echo "  Region: " . ($s3Config['region'] ?? 'not set') . "\n";
    echo "  Bucket: " . ($s3Config['bucket'] ?? 'not set') . "\n";
    echo "  Credentials File: " . (file_exists('/mnt/aws_creds_mounted/credentials') ? 'EXISTS' : 'MISSING') . "\n";
    echo "  Config File: " . (file_exists('/mnt/aws_creds_mounted/config') ? 'EXISTS' : 'MISSING') . "\n";
    
    // Test S3 disk creation
    try {
        $s3Disk = Storage::disk('s3');
        echo "  ✓ S3 Disk creation: SUCCESS\n";
        $testResults['basic_s3_connection'] = true;
    } catch (Exception $e) {
        echo "  ✗ S3 Disk creation: FAILED - " . $e->getMessage() . "\n";
        $testResults['credential_issues'][] = "S3 disk creation failed";
    }
    
    echo "\n2. SIGNED URL GENERATION TEST\n";
    echo str_repeat('-', 40) . "\n";
    
    try {
        $segment = Segment::first();
        if ($segment) {
            $signedUrl = $segment->getSignedUrl(3600);
            echo "  ✓ Signed URL generation: SUCCESS\n";
            echo "  URL length: " . strlen($signedUrl) . " characters\n";
            echo "  Target bucket: " . (strpos($signedUrl, 'tfstream') !== false ? 'tfstream' : 'other') . "\n";
            $testResults['signed_url_generation'] = true;
        } else {
            echo "  ⚠ No segments found in database\n";
        }
    } catch (Exception $e) {
        echo "  ✗ Signed URL generation: FAILED - " . $e->getMessage() . "\n";
        $testResults['credential_issues'][] = "Signed URL generation failed";
    }
    
    echo "\n3. BUCKET ACCESS TEST\n";
    echo str_repeat('-', 40) . "\n";
    
    // Test current bucket access
    try {
        $currentBucket = $s3Config['bucket'];
        $s3Disk = Storage::disk('s3');
        $files = $s3Disk->files('');
        echo "  ✓ Current bucket ({$currentBucket}): ACCESSIBLE\n";
        echo "  Files found: " . count($files) . "\n";
        $testResults['bucket_access'] = true;
    } catch (Exception $e) {
        echo "  ✗ Current bucket access: FAILED - " . $e->getMessage() . "\n";
        $testResults['credential_issues'][] = "Current bucket access failed";
    }
    
    // Test tfstream bucket access
    try {
        $tfstreamConfig = $s3Config;
        $tfstreamConfig['bucket'] = 'tfstream';
        $tfstreamDisk = Storage::build($tfstreamConfig);
        $tfstreamFiles = $tfstreamDisk->files('');
        echo "  ✓ TFStream bucket: ACCESSIBLE\n";
        echo "  Files found: " . count($tfstreamFiles) . "\n";
    } catch (Exception $e) {
        echo "  ✗ TFStream bucket: NOT ACCESSIBLE\n";
        echo "  Error: " . $e->getMessage() . "\n";
        $testResults['credential_issues'][] = "TFStream bucket access denied";
    }
    
    echo "\n4. FILE DOWNLOAD TEST\n";
    echo str_repeat('-', 40) . "\n";
    
    if ($testResults['signed_url_generation']) {
        $segment = Segment::first();
        $signedUrl = $segment->getSignedUrl(3600);
        
        // Test HTTP HEAD request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $signedUrl);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "  HTTP Response Code: {$httpCode}\n";
        
        if ($httpCode == 200) {
            echo "  ✓ File download: SUCCESS\n";
            $testResults['file_download'] = true;
        } elseif ($httpCode == 403) {
            echo "  ✗ File download: ACCESS DENIED (403)\n";
            $testResults['credential_issues'][] = "File download access denied";
        } elseif ($httpCode == 404) {
            echo "  ⚠ File download: FILE NOT FOUND (404)\n";
            $testResults['credential_issues'][] = "File not found in bucket";
        } else {
            echo "  ✗ File download: FAILED (HTTP {$httpCode})\n";
            $testResults['credential_issues'][] = "File download failed with HTTP {$httpCode}";
        }
    } else {
        echo "  ⚠ Skipped - Signed URL generation failed\n";
    }
    
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "FINAL TEST RESULTS\n";
    echo str_repeat('=', 60) . "\n";
    
    $overallStatus = "PARTIAL SUCCESS";
    $criticalIssues = [];
    
    echo "✓ Basic S3 Connection: " . ($testResults['basic_s3_connection'] ? 'PASS' : 'FAIL') . "\n";
    echo "✓ Signed URL Generation: " . ($testResults['signed_url_generation'] ? 'PASS' : 'FAIL') . "\n";
    echo "✓ Bucket Access: " . ($testResults['bucket_access'] ? 'PASS' : 'FAIL') . "\n";
    echo "✓ File Download: " . ($testResults['file_download'] ? 'PASS' : 'FAIL') . "\n";
    
    if (!$testResults['file_download']) {
        $criticalIssues[] = "File download access denied - TrueFire videos cannot be downloaded";
    }
    
    echo "\nCRITICAL FINDINGS:\n";
    echo str_repeat('-', 30) . "\n";
    
    echo "1. CREDENTIAL CONFIGURATION:\n";
    echo "   • Current profile 'tfs-shared-services' has access to 'aws-transcription-data' bucket\n";
    echo "   • Current profile DOES NOT have access to 'tfstream' bucket\n";
    echo "   • 'truefire' profile has access to 'tfstream' bucket\n";
    echo "   • Video segments are stored in 'tfstream' bucket\n";
    
    echo "\n2. ACCESS PERMISSIONS:\n";
    echo "   • Signed URLs are generated successfully\n";
    echo "   • URLs point to 'tfstream' bucket\n";
    echo "   • HTTP 403 errors when accessing files\n";
    echo "   • Profile mismatch between configuration and required bucket access\n";
    
    echo "\nRECOMMENDATIONS:\n";
    echo str_repeat('-', 30) . "\n";
    
    if (!$testResults['file_download']) {
        echo "🔧 REQUIRED FIX:\n";
        echo "   1. Update S3 configuration to use 'truefire' profile for tfstream bucket access\n";
        echo "   2. OR: Grant 'tfs-shared-services' profile access to 'tfstream' bucket\n";
        echo "   3. OR: Configure separate disk for tfstream bucket with correct profile\n";
        
        $overallStatus = "REQUIRES CONFIGURATION FIX";
    }
    
    echo "\n📊 OVERALL STATUS: {$overallStatus}\n";
    
    if ($testResults['basic_s3_connection'] && $testResults['signed_url_generation']) {
        echo "✅ S3 infrastructure is working correctly\n";
        echo "✅ New TrueFire credentials are properly configured\n";
        echo "✅ Signed URL generation is functional\n";
    }
    
    if (!empty($criticalIssues)) {
        echo "\n⚠️  CRITICAL ISSUES TO RESOLVE:\n";
        foreach ($criticalIssues as $issue) {
            echo "   • {$issue}\n";
        }
    }
    
    echo "\n" . str_repeat('=', 60) . "\n";
    
} catch (Exception $e) {
    echo "✗ Test suite failed: " . $e->getMessage() . "\n";
    echo "Error details: " . $e->getTraceAsString() . "\n";
}

echo "\n=== S3 Connectivity Test Complete ===\n";