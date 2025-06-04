<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CloudFrontSigningService;
use App\Jobs\DownloadTruefireSegmentV3;
use Exception;

class DebugV3JobFlow extends Command
{
    protected $signature = 'debug:v3-job-flow';
    protected $description = 'Debug the complete V3 job flow to identify where URL malformation occurs';

    public function handle()
    {
        $this->info('=== V3 Job Flow Debug ===');
        $this->newLine();

        try {
            // Create a mock segment object like the real job uses
            $mockSegment = (object) [
                'id' => 'test-segment-1748982286'
            ];
            
            $courseDir = 'test-course-dir';
            $courseId = 'test-course-123';
            
            $this->info('1. Mock Segment Data:');
            $this->line("   Segment ID: {$mockSegment->id}");
            $this->line("   Course Dir: {$courseDir}");
            $this->line("   Course ID: {$courseId}");
            $this->newLine();
            
            // Test the generateFreshSignedUrl method logic
            $this->info('2. Testing generateFreshSignedUrl() Logic:');
            
            // Get the CloudFront signing service
            $cloudFrontService = app(CloudFrontSigningService::class);
            
            // Construct the CloudFront URL for this segment (same as V3 job)
            $cloudFrontUrl = "https://d2kum0w8xvhbpf.cloudfront.net/truefire/{$mockSegment->id}.mp4";
            $this->line("   CloudFront URL: {$cloudFrontUrl}");
            
            // Generate signed URL with 2-hour expiration (same as V3 job)
            $expirationSeconds = 2 * 60 * 60; // 2 hours in seconds
            $this->line("   Expiration Seconds: {$expirationSeconds}");
            
            // Call signUrl with exact same parameters as V3 job
            $this->line("   Calling: cloudFrontService->signUrl('{$cloudFrontUrl}', '', {$expirationSeconds})");
            $signedUrl = $cloudFrontService->signUrl($cloudFrontUrl, '', $expirationSeconds);
            
            $this->info('3. Generated URL Analysis:');
            $this->line("   URL Length: " . strlen($signedUrl));
            $this->line("   Contains segment ID: " . (strpos($signedUrl, $mockSegment->id) !== false ? 'YES' : 'NO'));
            
            // Check for malformation patterns
            $expectedPath = "/truefire/{$mockSegment->id}.mp4";
            $urlParts = parse_url($signedUrl);
            $actualPath = $urlParts['path'] ?? '';
            
            $this->line("   Expected path: {$expectedPath}");
            $this->line("   Actual path: {$actualPath}");
            $this->line("   Path matches expected: " . ($expectedPath === $actualPath ? 'YES' : 'NO'));
            
            // Check for timestamp concatenation in path
            $timestampPattern = '/\.mp4\d{4}-\d{2}-\d{2}/';
            $hasTimestampInPath = preg_match($timestampPattern, $actualPath);
            $this->line("   Path contains timestamp: " . ($hasTimestampInPath ? 'YES - PROBLEM!' : 'NO'));
            
            // Check for URL-encoded timestamp
            $decodedUrl = urldecode($signedUrl);
            $timestampInDecoded = strpos($decodedUrl, date('Y-m-d H:i:s')) !== false;
            $this->line("   Decoded URL contains timestamp: " . ($timestampInDecoded ? 'YES - PROBLEM!' : 'NO'));
            
            $this->newLine();
            $this->info('4. Full Generated URL:');
            $this->line("   {$signedUrl}");
            $this->newLine();
            
            // Now test what happens when we create the actual job
            $this->info('5. Testing V3 Job Creation:');
            try {
                $job = new DownloadTruefireSegmentV3($mockSegment, $courseDir, $courseId);
                $this->line("   ✓ V3 Job created successfully");
                
                // Try to access the generateFreshSignedUrl method via reflection
                $reflection = new \ReflectionClass($job);
                $method = $reflection->getMethod('generateFreshSignedUrl');
                $method->setAccessible(true);
                
                $this->line("   Calling job->generateFreshSignedUrl() via reflection...");
                $jobSignedUrl = $method->invoke($job);
                
                $this->info('6. Job-Generated URL Analysis:');
                $this->line("   Job URL Length: " . strlen($jobSignedUrl));
                $this->line("   URLs match: " . ($signedUrl === $jobSignedUrl ? 'YES' : 'NO'));
                
                if ($signedUrl !== $jobSignedUrl) {
                    $this->error("   DIFFERENCE DETECTED!");
                    $this->line("   Direct service URL: {$signedUrl}");
                    $this->line("   Job-generated URL: {$jobSignedUrl}");
                    
                    // Analyze the difference
                    $jobUrlParts = parse_url($jobSignedUrl);
                    $jobPath = $jobUrlParts['path'] ?? '';
                    $this->line("   Job URL path: {$jobPath}");
                    
                    $jobHasTimestamp = preg_match($timestampPattern, $jobPath);
                    $this->line("   Job URL path has timestamp: " . ($jobHasTimestamp ? 'YES - PROBLEM FOUND!' : 'NO'));
                }
                
            } catch (Exception $e) {
                $this->error("   Failed to test job: " . $e->getMessage());
            }
            
            $this->newLine();
            $this->info('=== Debug Complete ===');
            
        } catch (Exception $e) {
            $this->error("ERROR: " . $e->getMessage());
            $this->line("Stack trace:");
            $this->line($e->getTraceAsString());
            return 1;
        }
        
        return 0;
    }
}