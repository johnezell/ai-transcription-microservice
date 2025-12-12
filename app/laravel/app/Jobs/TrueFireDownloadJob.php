<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Download a TrueFire video from S3 and trigger transcription.
 * 
 * This job runs asynchronously so the user gets immediate feedback
 * while the download and transcription happen in the background.
 */
class TrueFireDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes for large downloads
    public int $tries = 2;

    public string $videoId;
    public int $articleId;
    public int $segmentId;
    public string $s3Bucket;
    public string $s3Key;
    public string $jobId;
    public string $brandId;

    public function __construct(
        string $videoId,
        int $articleId,
        int $segmentId,
        string $s3Bucket,
        string $s3Key,
        string $jobId,
        string $brandId = 'truefire'
    ) {
        $this->videoId = $videoId;
        $this->articleId = $articleId;
        $this->segmentId = $segmentId;
        $this->s3Bucket = $s3Bucket;
        $this->s3Key = $s3Key;
        $this->jobId = $jobId;
        $this->brandId = $brandId;
    }

    public function handle(): void
    {
        Log::info('Starting TrueFire download job', [
            'video_id' => $this->videoId,
            'article_id' => $this->articleId,
            'segment_id' => $this->segmentId,
            's3_key' => $this->s3Key,
        ]);

        $video = Video::find($this->videoId);
        $article = Article::find($this->articleId);

        if (!$video || !$article) {
            Log::error('Video or Article not found for TrueFire download', [
                'video_id' => $this->videoId,
                'article_id' => $this->articleId,
            ]);
            return;
        }

        try {
            // Step 1: Create job directory
            $jobDir = storage_path("app/public/s3/jobs/{$this->jobId}");
            if (!is_dir($jobDir)) {
                mkdir($jobDir, 0755, true);
            }

            // Step 2: Download from S3
            $videoPath = "{$jobDir}/video.mp4";
            $this->downloadFromS3($videoPath);

            // Update video with file info
            $video->update([
                'storage_path' => $videoPath,
                'size_bytes' => filesize($videoPath),
                'status' => 'processing',
            ]);

            // Update article to show transcription step
            $segmentName = $video->original_filename;
            $article->update([
                'title' => 'Transcribing: ' . $segmentName,
                'content' => '<p>Extracting audio and transcribing with Whisper... This may take 2-5 minutes for longer videos.</p>',
            ]);

            Log::info('TrueFire video downloaded, triggering transcription', [
                'video_id' => $this->videoId,
                'segment_id' => $this->segmentId,
                'size' => filesize($videoPath),
            ]);

            // Step 3: Dispatch transcription job
            UploadedVideoTranscriptionJob::dispatch(
                $this->videoId,
                $this->articleId,
                $this->brandId
            );

        } catch (\Exception $e) {
            Log::error('TrueFire download job failed', [
                'video_id' => $this->videoId,
                'segment_id' => $this->segmentId,
                'error' => $e->getMessage(),
            ]);

            $video->update([
                'status' => 'failed',
                'metadata' => array_merge($video->metadata ?? [], [
                    'error_message' => $e->getMessage(),
                ]),
            ]);

            $article->update([
                'title' => str_replace(['Downloading:', 'Transcribing:', 'Processing:'], 'Error:', $article->title),
                'status' => 'error',
                'error_message' => 'Download failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Download from S3 using pre-signed URL
     */
    protected function downloadFromS3(string $destPath): void
    {
        Log::info('Downloading from S3', [
            'bucket' => $this->s3Bucket,
            'key' => $this->s3Key,
            'dest_path' => $destPath,
        ]);

        $s3Client = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        ]);

        // Generate a pre-signed URL valid for 1 hour
        $cmd = $s3Client->getCommand('GetObject', [
            'Bucket' => $this->s3Bucket,
            'Key' => $this->s3Key,
        ]);
        
        $presignedUrl = (string) $s3Client->createPresignedRequest($cmd, '+1 hour')->getUri();

        // Download using cURL
        $ch = curl_init($presignedUrl);
        $fp = fopen($destPath, 'wb');
        
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1200); // 20 minutes for large files
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept-Encoding: identity',
        ]);
        
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        fclose($fp);
        
        if (!$success || $httpCode !== 200) {
            if (file_exists($destPath)) {
                unlink($destPath);
            }
            throw new \Exception("Failed to download from S3: HTTP {$httpCode} - {$error}");
        }

        if (!file_exists($destPath) || filesize($destPath) === 0) {
            throw new \Exception("S3 download completed but file is missing or empty");
        }

        Log::info('S3 download completed', [
            'size' => filesize($destPath),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('TrueFireDownloadJob failed permanently', [
            'video_id' => $this->videoId,
            'article_id' => $this->articleId,
            'segment_id' => $this->segmentId,
            'error' => $exception->getMessage(),
        ]);

        $article = Article::find($this->articleId);
        if ($article) {
            $article->update([
                'status' => 'error',
                'error_message' => 'Download failed: ' . $exception->getMessage(),
            ]);
        }
    }
}
