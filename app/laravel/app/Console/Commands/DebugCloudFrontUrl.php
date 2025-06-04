<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CloudFrontSigningService;
use Exception;

class DebugCloudFrontUrl extends Command
{
    protected $signature = 'debug:cloudfront-url';
    protected $description = 'Debug CloudFront URL generation to identify malformation issue';

    public function handle()
    {
        $this->info('=== CloudFront URL Generation Debug ===');
        $this->newLine();

        try {
            // Create the service
            $cloudFrontService = app(CloudFrontSigningService::class);
            
            // Test the exact same parameters as V3 job
            $segmentId = "test-segment-1748982286";
            $cloudFrontUrl = "https://d2kum0w8xvhbpf.cloudfront.net/truefire/{$segmentId}.mp4";
            $expirationSeconds = 2 * 60 * 60; // 2 hours
            
            $this->info('1. Input Parameters:');
            $this->line("   Segment ID: {$segmentId}");
            $this->line("   CloudFront URL: {$cloudFrontUrl}");
            $this->line("   Expiration Seconds: {$expirationSeconds}");
            $this->line("   Current Time: " . date('Y-m-d H:i:s'));
            $this->line("   Expected Expiry: " . date('Y-m-d H:i:s', time() + $expirationSeconds));
            $this->newLine();
            
            $this->info('2. CloudFrontSigningService.signUrl() Call:');
            $this->line("   Method: signUrl(server, file, seconds, whitelist)");
            $this->line("   server = '{$cloudFrontUrl}'");
            $this->line("   file = ''");
            $this->line("   seconds = {$expirationSeconds}");
            $this->line("   whitelist = false");
            $this->newLine();
            
            // Add debug logging to see what happens inside signUrl
            $this->info('3. Internal Processing (simulated):');
            $server = $cloudFrontUrl;
            $file = '';
            $seconds = $expirationSeconds;
            $filePath = $server . $file;
            $expires = time() + $seconds;
            
            $this->line("   \$filePath = \$server . \$file = '{$server}' . '{$file}' = '{$filePath}'");
            $this->line("   \$expires = time() + \$seconds = " . time() . " + {$seconds} = {$expires}");
            $this->line("   \$expires (formatted) = " . date('Y-m-d H:i:s', $expires));
            $this->newLine();
            
            // Now call the actual service
            $this->info('4. Calling CloudFrontSigningService.signUrl()...');
            $signedUrl = $cloudFrontService->signUrl($cloudFrontUrl, '', $expirationSeconds);
            
            $this->info('5. Result Analysis:');
            $this->line("   Signed URL Length: " . strlen($signedUrl));
            $this->line("   Contains 'Expires': " . (strpos($signedUrl, 'Expires') !== false ? 'YES' : 'NO'));
            $this->line("   Contains 'Signature': " . (strpos($signedUrl, 'Signature') !== false ? 'YES' : 'NO'));
            $this->line("   Contains 'Key-Pair-Id': " . (strpos($signedUrl, 'Key-Pair-Id') !== false ? 'YES' : 'NO'));
            $this->newLine();
            
            // Check for the malformation pattern
            $expectedFilename = "{$segmentId}.mp4";
            $malformedPattern = $expectedFilename . date('Y-m-d');
            
            $this->info('6. Malformation Check:');
            $this->line("   Expected filename: {$expectedFilename}");
            $this->line("   Malformed pattern: {$malformedPattern}");
            $malformedFound = strpos($signedUrl, $malformedPattern) !== false;
            $this->line("   URL contains malformed pattern: " . ($malformedFound ? 'YES - PROBLEM FOUND!' : 'NO'));
            $this->newLine();
            
            // Extract and analyze URL components
            $this->info('7. URL Component Analysis:');
            $urlParts = parse_url($signedUrl);
            $this->line("   Scheme: " . ($urlParts['scheme'] ?? 'N/A'));
            $this->line("   Host: " . ($urlParts['host'] ?? 'N/A'));
            $this->line("   Path: " . ($urlParts['path'] ?? 'N/A'));
            $this->line("   Query: " . ($urlParts['query'] ?? 'N/A'));
            $this->newLine();
            
            // Check if path contains timestamp
            if (isset($urlParts['path'])) {
                $pathContainsTimestamp = preg_match('/\.mp4\d{4}-\d{2}-\d{2}/', $urlParts['path']);
                $this->line("   Path contains timestamp concatenation: " . ($pathContainsTimestamp ? 'YES - PROBLEM CONFIRMED!' : 'NO'));
            }
            
            $this->info('8. Full Signed URL:');
            $this->line("   {$signedUrl}");
            $this->newLine();
            
            // Additional analysis - check for URL encoding issues
            $this->info('9. URL Encoding Analysis:');
            $decodedUrl = urldecode($signedUrl);
            $this->line("   URL is URL-encoded: " . ($signedUrl !== $decodedUrl ? 'YES' : 'NO'));
            if ($signedUrl !== $decodedUrl) {
                $this->line("   Decoded URL: {$decodedUrl}");
                // Check if decoded version has the malformation
                $decodedMalformed = strpos($decodedUrl, $expectedFilename . date('Y-m-d H:i:s')) !== false;
                $this->line("   Decoded URL contains timestamp: " . ($decodedMalformed ? 'YES - TIMESTAMP CONCATENATION CONFIRMED!' : 'NO'));
            }
            
            $this->newLine();
            $this->info('=== Debug Complete ===');
            
            // Summary
            if ($malformedFound || (isset($pathContainsTimestamp) && $pathContainsTimestamp)) {
                $this->error('PROBLEM CONFIRMED: URL malformation detected!');
                return 1;
            } else {
                $this->info('No malformation detected in this test.');
                return 0;
            }
            
        } catch (Exception $e) {
            $this->error("ERROR: " . $e->getMessage());
            $this->line("Stack trace:");
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
}