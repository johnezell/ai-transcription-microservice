<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class DownloadTruefireSegmentV2 implements ShouldQueue
{
    use Queueable;

    public $timeout = 600; // 10 minutes per file (increased for large files)
    public $tries = 5; // More retry attempts
    public $maxExceptions = 3; // Allow some exceptions before failing
    public $backoff = [30, 60, 120, 300, 600]; // Exponential backoff in seconds

    private $segment;
    private $courseDir;
    private $signedUrl;
    private $courseId;

    /**
     * Create a new job instance.
     */
    public function __construct($segment, $courseDir, $signedUrl, $courseId = null)
    {
        $this->segment = $segment;
        $this->courseDir = $courseDir;
        $this->signedUrl = $signedUrl;
        $this->courseId = $courseId;
        
        // Set queue name for better organization
        $this->onQueue('downloads');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Rate limiting: max 5 concurrent downloads
        $this->enforceRateLimit();
        
        try {
            Log::info("Starting download for segment {$this->segment->id} (Course: {$this->courseId})");
            
            // Create filename from video_file or segment ID
            $videoFile = $this->segment->video_file ?? "segment-{$this->segment->id}";
            $filename = $videoFile . '.mp4';
            $singleDownloadFolder = 'downloads'; // Single folder for all downloads
            $filePath = storage_path($singleDownloadFolder . '/' . $filename);
            
            // Check if file already exists and is valid
            if ($this->isFileAlreadyDownloaded($filePath)) {
                Log::info("File already exists and is valid, skipping: {$filePath}");
                $this->updateDownloadStats('skipped');
                return;
            }
            
            // Download the file with retry logic
            $this->downloadFile($filePath);
            
            // Verify download
            if ($this->verifyDownload($filePath)) {
                Log::info("Successfully downloaded and verified segment {$this->segment->id}: {$filename}");
                $this->updateDownloadStats('success');
            } else {
                throw new \Exception("Downloaded file failed verification");
            }
            
        } catch (RequestException $e) {
            $this->handleDownloadError($e, 'cURL error');
            
        } catch (\Exception $e) {
            $this->handleDownloadError($e, 'Download failed');
        } finally {
            // Release rate limit slot
            $this->releaseRateLimit();
        }
    }

    /**
     * Enforce rate limiting to prevent overwhelming CloudFront
     */
    private function enforceRateLimit(): void
    {
        $maxConcurrent = 5;
        $lockKey = 'download_rate_limit';
        
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
        
        // Add small delay between requests
        usleep(rand(500000, 1500000)); // 0.5-1.5 second random delay
    }

    /**
     * Release rate limit slot
     */
    private function releaseRateLimit(): void
    {
        $lockKey = 'download_rate_limit';
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
        $singleDownloadFolder = 'downloads'; // Single folder for all downloads
        $fullPath = storage_path($singleDownloadFolder . '/' . basename($filePath));
        
        if (!Storage::disk('local')->exists($fullPath)) {
            return false;
        }
        
        $fileSize = Storage::disk('local')->size($fullPath);
        
        // Consider files smaller than 1KB as invalid (likely error pages)
        return $fileSize > 1024;
    }

    /**
     * Download file with proper error handling
     */
    private function downloadFile(string $filePath): void
    {
        // Create Guzzle client with optimized configuration
        $client = new Client([
            'timeout' => 300, // 5 minutes per request
            'connect_timeout' => 30, // 30 seconds to connect
            'verify' => false,
            'decode_content' => false, // Don't decode content automatically
            'stream' => true, // Stream large files to avoid memory issues
            'headers' => [
                'Accept-Encoding' => 'identity', // Request no encoding
                'User-Agent' => 'Laravel-TrueFire-Downloader/2.0',
                'Accept' => '*/*',
                'Connection' => 'keep-alive'
            ]
        ]);

        Log::info("Downloading from: {$this->signedUrl}");
        
        // Download the file
        $response = $client->get($this->signedUrl);
        
        if ($response->getStatusCode() !== 200) {
            throw new \Exception("HTTP {$response->getStatusCode()}: Failed to download from signed URL");
        }
        
        // Save the file using streaming to handle large files
        $stream = $response->getBody();
        Storage::disk('local')->put($filePath, $stream->getContents());
    }

    /**
     * Verify downloaded file is valid
     */
    private function verifyDownload(string $filePath): bool
    {
        if (!Storage::disk('local')->exists($filePath)) {
            return false;
        }
        
        $fileSize = Storage::disk('local')->size($filePath);
        
        // Basic validation: file should be larger than 1KB
        if ($fileSize < 1024) {
            Log::warning("Downloaded file is too small ({$fileSize} bytes): {$filePath}");
            return false;
        }
        
        // Additional validation: check if it's actually a video file
        $content = Storage::disk('local')->get($filePath);
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
            'signed_url' => $this->signedUrl,
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
        $key = "download_stats_{$this->courseId}";
        $stats = Cache::get($key, ['success' => 0, 'failed' => 0, 'skipped' => 0]);
        $stats[$status]++;
        Cache::put($key, $stats, 3600); // Store for 1 hour
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
}