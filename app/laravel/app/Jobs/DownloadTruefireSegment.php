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
            
            // Create filename from video_file or segment ID
            $videoFile = $this->segment->video_file ?? "segment-{$this->segment->id}";
            $filename = $videoFile . '.mp4';
            $filePath = $this->courseDir . '/' . $filename;

            // Check if file already exists
            if (Storage::disk('local')->exists($filePath)) {
                Log::info("File already exists, skipping: {$filePath}");
                return;
            }

            // Create Guzzle client with proper configuration for CloudFront
            $client = new Client([
                'timeout' => 120, // 2 minutes per request
                'verify' => false,
                'decode_content' => false, // Don't decode content automatically
                'headers' => [
                    'Accept-Encoding' => 'identity', // Request no encoding
                    'User-Agent' => 'Laravel-TrueFire-Downloader/1.0'
                ]
            ]);

            Log::info("Downloading from: {$this->signedUrl}");
            
            // Download the file
            $response = $client->get($this->signedUrl);
            
            if ($response->getStatusCode() === 200) {
                // Save the file
                Storage::disk('local')->put($filePath, $response->getBody()->getContents());
                
                $fileSize = Storage::disk('local')->size($filePath);
                Log::info("Successfully downloaded segment {$this->segment->id}: {$filename} ({$fileSize} bytes)");
            } else {
                throw new \Exception("HTTP {$response->getStatusCode()}: Failed to download from signed URL");
            }

        } catch (RequestException $e) {
            $error = "cURL error for segment {$this->segment->id}: " . $e->getMessage();
            Log::error($error);
            throw new \Exception($error);
            
        } catch (\Exception $e) {
            $error = "Download failed for segment {$this->segment->id}: " . $e->getMessage();
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
