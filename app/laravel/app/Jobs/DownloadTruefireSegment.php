<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class DownloadTruefireSegment implements ShouldQueue
{
    use Queueable;

    public $timeout = 300; // 5 minutes per file
    public $tries = 3; // Retry failed downloads up to 3 times

    private $segment;
    private $courseDir;
    private $signedUrl;

    /**
     * Create a new job instance.
     */
    public function __construct($segment, $courseDir, $signedUrl)
    {
        $this->segment = $segment;
        $this->courseDir = $courseDir;
        $this->signedUrl = $signedUrl;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Starting download for segment {$this->segment->id}");
            
            // Create filename using segment ID (no prefix)
            $filename = "{$this->segment->id}.mp4";
            $filePath = $this->courseDir . '/' . $filename;

            // Check if file already exists
            if (Storage::disk('local')->exists($filePath)) {
                Log::info("File already exists, skipping: {$filePath}");
                return;
            }

            // Create Guzzle client with proper configuration for S3
            $client = new Client([
                'timeout' => 120, // 2 minutes per request
                'verify' => true, // Enable SSL verification for S3
                'decode_content' => false, // Don't decode content automatically
                'headers' => [
                    'User-Agent' => 'Laravel-TrueFire-S3-Downloader/1.0'
                ]
            ]);

            Log::info("Downloading from S3: {$this->signedUrl}");
            
            // Download the file
            $response = $client->get($this->signedUrl);
            
            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                // Save the file
                Storage::disk('local')->put($filePath, $response->getBody()->getContents());
                
                $fileSize = Storage::disk('local')->size($filePath);
                Log::info("Successfully downloaded segment {$this->segment->id}: {$filename} ({$fileSize} bytes)");
            } else {
                // Handle S3-specific error responses
                $errorMessage = "HTTP {$statusCode}: Failed to download from S3 signed URL";
                
                if ($statusCode === 403) {
                    $errorMessage .= " (Access Denied - URL may have expired or insufficient permissions)";
                } elseif ($statusCode === 404) {
                    $errorMessage .= " (File not found in S3 bucket)";
                } elseif ($statusCode >= 500) {
                    $errorMessage .= " (S3 server error - may be temporary)";
                }
                
                throw new \Exception($errorMessage);
            }

        } catch (RequestException $e) {
            $error = "S3 request error for segment {$this->segment->id}: " . $e->getMessage();
            Log::error($error);
            throw new \Exception($error);
            
        } catch (\Exception $e) {
            $error = "S3 download failed for segment {$this->segment->id}: " . $e->getMessage();
            Log::error($error);
            throw $e; // Re-throw to trigger job retry
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Job failed permanently for segment {$this->segment->id}: " . $exception->getMessage());
    }
}
