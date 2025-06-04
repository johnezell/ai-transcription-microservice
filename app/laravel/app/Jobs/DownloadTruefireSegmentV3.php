<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Aws\S3\S3Client;

class DownloadTruefireSegmentV3 implements ShouldQueue
{
    use Queueable;

    public $timeout = 600; // 10 minutes per file
    public $tries = 5; // More retry attempts
    public $maxExceptions = 3; // Allow some exceptions before failing
    public $backoff = [30, 60, 120, 300, 600]; // Exponential backoff in seconds

    private $segment;
    private $courseDir;
    private $courseId;
    private $s3Path;

    /**
     * Create a new job instance.
     * 
     * IMPORTANT: We only store segment data, NOT the signed URL
     * The signed URL will be generated fresh when the job executes
     */
    public function __construct($segment, $courseDir, $courseId = null, $s3Path = null)
    {
        $this->segment = $segment;
        $this->courseDir = $courseDir;
        $this->courseId = $courseId;
        $this->s3Path = $s3Path;
            // Set queue name for better organization
        $this->onQueue('downloads');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Rate limiting: max 10 concurrent downloads
        $this->enforceRateLimit();
        
        // Mark segment as processing
        $this->updateQueueStatus('processing');
        
        try {
            Log::info("Starting download for segment {$this->segment->id} (Course: {$this->courseId})");
            
            // Create filename using segment ID to match controller expectations
            $filename = "{$this->segment->id}.mp4";
            $filePath = "{$this->courseDir}/{$filename}";
            
            // Check if file already exists and is valid
            if ($this->isFileAlreadyDownloaded($filePath)) {
                Log::info("File already exists and is valid, skipping: {$filePath}");
                $this->updateDownloadStats('skipped');
                $this->updateQueueStatus('completed');
                return;
            }
            
            // Generate fresh signed URL at execution time
            $signedUrl = $this->generateFreshSignedUrl();
            
            // Download the file with retry logic
            $this->downloadFile($filePath, $signedUrl);
            
            // Verify download
            if ($this->verifyDownload($filePath)) {
                Log::info("Successfully downloaded and verified segment {$this->segment->id}: {$filename}");
                $this->updateDownloadStats('success');
                $this->updateQueueStatus('completed');
            } else {
                throw new \Exception("Downloaded file failed verification");
            }
            
        } catch (RequestException $e) {
            $this->handleDownloadError($e, 'cURL error');
            
        } catch (\Exception $e) {
            $this->handleDownloadError($e, 'Download failed');
        } finally {
            // Always clean up processing status and release rate limit
            $this->updateQueueStatus('completed');
            $this->releaseRateLimit();
        }
    }

    /**
     * Generate a fresh signed URL at execution time using S3
     * This prevents URL expiration issues for long-running queues
     */
    private function generateFreshSignedUrl(): string
    {
        try {
            Log::info("Generating fresh S3 signed URL for segment {$this->segment->id}");
            
            // Create S3 client using the truefire profile
            $s3Client = new S3Client([
                'version' => 'latest',
                'region' => config('filesystems.disks.s3.region', 'us-east-1'),
                'profile' => 'truefire', // Use the truefire AWS profile
                'credentials' => [
                    'key' => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ]
            ]);
            
            // S3 bucket and object key
            $bucket = 'tfstream';
            $key = $this->s3Path;
            
            // Generate signed URL with 2-hour expiration (plenty of time for download)
            $expirationTime = '+2 hours';
            $command = $s3Client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key' => $key
            ]);
            
            $signedUrl = (string) $s3Client->createPresignedRequest($command, $expirationTime)->getUri();
            
            Log::info("Generated fresh S3 signed URL for segment {$this->segment->id}", [
                'url_length' => strlen($signedUrl),
                'bucket' => $bucket,
                'key' => $key,
                'expires_in' => $expirationTime
            ]);
            
            return $signedUrl;
            
        } catch (\Exception $e) {
            Log::error("Failed to generate S3 signed URL for segment {$this->segment->id}: " . $e->getMessage());
            throw new \Exception("Failed to generate S3 signed URL: " . $e->getMessage());
        }
    }

    /**
     * Enforce rate limiting to prevent overwhelming S3/CloudFront
     */
    private function enforceRateLimit(): void
    {
        $maxConcurrent = 10; // Reasonable limit for CloudFront
        $lockKey = 'download_rate_limit_v3';
        
        // Wait for available slot (max 60 seconds)
        $attempts = 0;
        while ($attempts < 60) {
            $current = Cache::get($lockKey, 0);
            if ($current < $maxConcurrent) {
                Cache::increment($lockKey);
                Cache::put($lockKey, $current + 1, 3600); // 1 hour expiry
                break;
            }
            
            sleep(1);
            $attempts++;
        }
        
        if ($attempts >= 60) {
            throw new \Exception("Rate limit timeout - too many concurrent downloads");
        }
        
        // Small delay to prevent overwhelming the service
        usleep(rand(100000, 500000)); // 0.1-0.5 second random delay
    }

    /**
     * Release rate limit slot
     */
    private function releaseRateLimit(): void
    {
        $lockKey = 'download_rate_limit_v3';
        $current = Cache::get($lockKey, 0);
        if ($current > 0) {
            Cache::decrement($lockKey);
        }
    }

    /**
     * Check if file already exists and is valid
     */
    private function isFileAlreadyDownloaded(string $filePath): bool
    {
        // Use the configured default disk explicitly to ensure consistency
        $disk = config('filesystems.default');
        if (!Storage::disk($disk)->exists($filePath)) {
            return false;
        }
        
        $fileSize = Storage::disk($disk)->size($filePath);
        
        // Consider files smaller than 1KB as invalid (likely error pages)
        return $fileSize > 1024;
    }

    /**
     * Download file with proper error handling for S3
     */
    private function downloadFile(string $filePath, string $signedUrl): void
    {
        // Create Guzzle client optimized for S3
        $client = new Client([
            'timeout' => 300, // 5 minutes per request
            'connect_timeout' => 30, // 30 seconds to connect
            'verify' => true, // Enable SSL verification for S3
            'decode_content' => false, // Don't decode content automatically
            'stream' => true, // Stream large files to avoid memory issues
            'headers' => [
                'User-Agent' => 'Laravel-TrueFire-S3-Downloader/3.0',
                'Accept' => '*/*'
            ]
        ]);

        Log::info("Downloading from S3 with fresh signed URL", [
            'segment_id' => $this->segment->id,
            'url_length' => strlen($signedUrl)
        ]);
        
        // Download the file
        $response = $client->get($signedUrl);
        
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            // Handle S3-specific error responses
            $errorMessage = "HTTP {$statusCode}: Failed to download from S3 signed URL";
            
            if ($statusCode === 400) {
                $errorMessage .= " (Bad Request - likely AWS credentials or signature issue)";
            } elseif ($statusCode === 403) {
                $errorMessage .= " (Access Denied - URL may have expired or insufficient permissions)";
            } elseif ($statusCode === 404) {
                $errorMessage .= " (File not found in S3 bucket)";
            } elseif ($statusCode >= 500) {
                $errorMessage .= " (S3 server error - may be temporary)";
            }
            
            // Log additional debugging information for 400/403 errors
            if ($statusCode === 400 || $statusCode === 403) {
                Log::error("S3 download authentication error", [
                    'segment_id' => $this->segment->id,
                    'status_code' => $statusCode,
                    'url_length' => strlen($signedUrl),
                    'url_contains_signature' => strpos($signedUrl, 'X-Amz-Signature') !== false,
                    'url_contains_tfstream' => strpos($signedUrl, 'tfstream') !== false,
                ]);
            }
            
            throw new \Exception($errorMessage);
        }
        
        // Save the file using streaming to handle large files
        // Use the configured default disk explicitly to ensure consistency
        $disk = config('filesystems.default');
        $stream = $response->getBody();
        Storage::disk($disk)->put($filePath, $stream->getContents());
    }

    /**
     * Verify downloaded file is valid
     */
    private function verifyDownload(string $filePath): bool
    {
        // Use the configured default disk explicitly to ensure consistency
        $disk = config('filesystems.default');
        if (!Storage::disk($disk)->exists($filePath)) {
            return false;
        }
        
        $fileSize = Storage::disk($disk)->size($filePath);
        
        // Basic validation: file should be larger than 1KB
        if ($fileSize < 1024) {
            Log::warning("Downloaded file is too small ({$fileSize} bytes): {$filePath}");
            return false;
        }
        
        // Additional validation: check if it's actually a video file
        $content = Storage::disk($disk)->get($filePath);
        $header = substr($content, 0, 12);
        
        // Check for common video file signatures
        $videoSignatures = [
            "\x00\x00\x00\x18ftypmp4", // MP4
            "\x00\x00\x00\x20ftypmp4", // MP4
            "\x1A\x45\xDF\xA3", // WebM/MKV
            "FLV\x01", // FLV
        ];
        
        foreach ($videoSignatures as $signature) {
            if (strpos($header, $signature) !== false) {
                return true;
            }
        }
        
        // If no video signature found, log warning but don't fail
        // (some video files might have different headers)
        Log::warning("Downloaded file doesn't match expected video signatures: {$filePath}");
        return $fileSize > 10240; // At least 10KB
    }

    /**
     * Handle download errors with detailed logging
     */
    private function handleDownloadError(\Throwable $e, string $context): void
    {
        $error = "{$context} for segment {$this->segment->id}: " . $e->getMessage();
        Log::error($error, [
            'segment_id' => $this->segment->id,
            'course_id' => $this->courseId,
            'attempt' => $this->attempts(),
            'exception' => get_class($e)
        ]);
        
        $this->updateDownloadStats('failed');
        throw new \Exception($error);
    }

    /**
     * Update download statistics
     */
    private function updateDownloadStats(string $status): void
    {
        // Ensure valid status values
        $validStatuses = ['success', 'failed', 'skipped'];
        if (!in_array($status, $validStatuses)) {
            Log::warning("Invalid status provided to updateDownloadStats: {$status}");
            return;
        }
        
        // Update course-specific stats with proper initialization
        $courseKey = "download_stats_{$this->courseId}";
        $defaultStats = ['success' => 0, 'failed' => 0, 'skipped' => 0];
        $courseStats = Cache::get($courseKey, $defaultStats);
        
        // Ensure all required keys exist
        $courseStats = array_merge($defaultStats, $courseStats);
        $courseStats[$status]++;
        Cache::put($courseKey, $courseStats, 3600); // Store for 1 hour
        
        // Update bulk download stats with proper initialization
        $bulkKey = "bulk_download_stats";
        $bulkStats = Cache::get($bulkKey, $defaultStats);
        
        // Ensure all required keys exist
        $bulkStats = array_merge($defaultStats, $bulkStats);
        $bulkStats[$status]++;
        Cache::put($bulkKey, $bulkStats, 3600); // Store for 1 hour
        
        Log::debug("Updated download stats", [
            'status' => $status,
            'segment_id' => $this->segment->id,
            'course_id' => $this->courseId,
            'course_stats' => $courseStats,
            'bulk_stats' => $bulkStats
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Job failed permanently for segment {$this->segment->id}: " . $exception->getMessage(), [
            'segment_id' => $this->segment->id,
            'course_id' => $this->courseId,
            'total_attempts' => $this->tries,
            'exception' => get_class($exception)
        ]);
        
        $this->updateDownloadStats('failed');
        $this->releaseRateLimit();
    }

    /**
     * Update queue status for this segment
     */
    private function updateQueueStatus(string $status): void
    {
        // Update course-specific processing status
        $courseProcessingKey = "processing_segments_{$this->courseId}";
        $courseProcessingSegments = Cache::get($courseProcessingKey, []);
        
        // Update bulk processing status
        $bulkProcessingKey = "bulk_processing_courses";
        $bulkProcessingSegments = Cache::get($bulkProcessingKey, []);
        
        if ($status === 'processing') {
            // Add to processing lists
            if (!in_array($this->segment->id, $courseProcessingSegments)) {
                $courseProcessingSegments[] = $this->segment->id;
                Cache::put($courseProcessingKey, $courseProcessingSegments, 3600);
            }
            if (!in_array($this->segment->id, $bulkProcessingSegments)) {
                $bulkProcessingSegments[] = $this->segment->id;
                Cache::put($bulkProcessingKey, $bulkProcessingSegments, 3600);
            }
        } elseif ($status === 'completed') {
            // Remove from processing lists
            $courseProcessingSegments = array_filter($courseProcessingSegments, function($id) {
                return $id !== $this->segment->id;
            });
            Cache::put($courseProcessingKey, array_values($courseProcessingSegments), 3600);
            
            $bulkProcessingSegments = array_filter($bulkProcessingSegments, function($id) {
                return $id !== $this->segment->id;
            });
            Cache::put($bulkProcessingKey, array_values($bulkProcessingSegments), 3600);
        }
        
        Log::debug("Updated queue status for segment {$this->segment->id}", [
            'status' => $status,
            'course_id' => $this->courseId,
            'course_processing_count' => count($courseProcessingSegments),
            'bulk_processing_count' => count($bulkProcessingSegments)
        ]);
    }
}