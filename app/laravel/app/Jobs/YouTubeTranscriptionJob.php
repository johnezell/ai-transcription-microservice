<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * YouTube Transcription Job
 * 
 * Downloads audio from YouTube and processes it through
 * the existing Whisper transcription pipeline with 
 * industry-specific settings.
 */
class YouTubeTranscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes
    public int $tries = 2;

    public string $youtubeUrl;
    public int $articleId;
    public string $brandId;
    public ?array $metadata;

    public function __construct(
        string $youtubeUrl, 
        int $articleId, 
        string $brandId = 'truefire',
        ?array $metadata = null
    ) {
        $this->youtubeUrl = $youtubeUrl;
        $this->articleId = $articleId;
        $this->brandId = $brandId;
        $this->metadata = $metadata;
    }

    public function handle(): void
    {
        Log::info('Starting YouTube transcription job', [
            'youtube_url' => $this->youtubeUrl,
            'article_id' => $this->articleId,
            'brand_id' => $this->brandId,
        ]);

        $article = Article::find($this->articleId);
        if (!$article) {
            Log::error('Article not found for YouTube transcription', ['article_id' => $this->articleId]);
            return;
        }

        try {
            // Step 1: Extract video ID
            $videoId = $this->extractVideoId($this->youtubeUrl);
            if (!$videoId) {
                throw new \Exception('Could not extract YouTube video ID');
            }

            // Step 2: Download audio from YouTube using yt-dlp
            $audioPath = $this->downloadYouTubeAudio($videoId);
            
            // Step 3: Create a Video record and process through Whisper
            $video = $this->createVideoRecord($videoId, $audioPath);
            
            // Link article to video (keep status as 'generating')
            $article->update([
                'video_id' => $video->id,
            ]);

            // Step 4: Trigger transcription through the audio service
            $this->triggerTranscription($video);

            Log::info('YouTube transcription job dispatched successfully', [
                'video_id' => $video->id,
                'article_id' => $this->articleId,
            ]);

        } catch (\Exception $e) {
            Log::error('YouTube transcription job failed', [
                'article_id' => $this->articleId,
                'error' => $e->getMessage(),
            ]);

            $article->update([
                'status' => 'error',
                'error_message' => 'Failed to process YouTube video: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract video ID from YouTube URL
     */
    protected function extractVideoId(string $url): ?string
    {
        $patterns = [
            '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/v\/)([a-zA-Z0-9_-]{11})/',
            '/^([a-zA-Z0-9_-]{11})$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Download audio from YouTube using yt-dlp
     */
    protected function downloadYouTubeAudio(string $videoId): string
    {
        $jobDir = storage_path("app/public/s3/jobs/yt-{$videoId}");
        
        if (!is_dir($jobDir)) {
            mkdir($jobDir, 0755, true);
        }

        $audioPath = "{$jobDir}/audio.wav";
        $tempPath = "{$jobDir}/audio_temp";

        Log::info('Downloading YouTube audio', ['video_id' => $videoId, 'output' => $audioPath]);

        // Use yt-dlp to download audio
        $result = Process::timeout(300)->run(
            "yt-dlp -x --audio-format wav --audio-quality 0 " .
            "-o \"{$tempPath}.%(ext)s\" " .
            "\"https://www.youtube.com/watch?v={$videoId}\" 2>&1"
        );

        if (!$result->successful()) {
            // Try with ffmpeg conversion if direct wav fails
            $result = Process::timeout(300)->run(
                "yt-dlp -x --audio-format mp3 --audio-quality 0 " .
                "-o \"{$tempPath}.%(ext)s\" " .
                "\"https://www.youtube.com/watch?v={$videoId}\" 2>&1"
            );

            if (!$result->successful()) {
                throw new \Exception('Failed to download YouTube audio: ' . $result->output());
            }

            // Convert mp3 to wav using ffmpeg
            $mp3Path = "{$tempPath}.mp3";
            if (file_exists($mp3Path)) {
                Process::timeout(120)->run(
                    "ffmpeg -y -i \"{$mp3Path}\" -ar 16000 -ac 1 -acodec pcm_s16le \"{$audioPath}\""
                );
                unlink($mp3Path);
            }
        } else {
            // Rename the wav file
            $tempWav = "{$tempPath}.wav";
            if (file_exists($tempWav)) {
                // Convert to proper format for Whisper (16kHz mono)
                Process::timeout(120)->run(
                    "ffmpeg -y -i \"{$tempWav}\" -ar 16000 -ac 1 -acodec pcm_s16le \"{$audioPath}\""
                );
                unlink($tempWav);
            }
        }

        if (!file_exists($audioPath)) {
            throw new \Exception('Audio file was not created after download');
        }

        Log::info('YouTube audio downloaded successfully', [
            'video_id' => $videoId,
            'file_size' => filesize($audioPath),
        ]);

        return $audioPath;
    }

    /**
     * Create a Video record for the YouTube content
     */
    protected function createVideoRecord(string $videoId, string $audioPath): Video
    {
        $video = Video::create([
            'original_filename' => "youtube-{$videoId}.mp4",
            'mime_type' => 'video/mp4',
            'size_bytes' => file_exists($audioPath) ? filesize($audioPath) : 0,
            'status' => 'processing',
            'audio_path' => $audioPath,
            'metadata' => [
                'source' => 'youtube',
                'youtube_video_id' => $videoId,
                'youtube_url' => $this->youtubeUrl,
                'brand_id' => $this->brandId,
                'title' => $this->metadata['title'] ?? null,
                'channel' => $this->metadata['channel'] ?? null,
            ],
        ]);

        // Create transcription log
        \App\Models\TranscriptionLog::create([
            'video_id' => $video->id,
            'job_id' => $video->id,
            'status' => 'processing',
            'started_at' => now(),
            'audio_extraction_started_at' => now(),
            'audio_extraction_completed_at' => now(), // Audio already extracted from YT
        ]);

        return $video;
    }

    /**
     * Trigger transcription through the existing service
     */
    protected function triggerTranscription(Video $video): void
    {
        $transcriptionServiceUrl = env('TRANSCRIPTION_SERVICE_URL', 'http://transcription-service:5000');

        Log::info('Triggering Whisper transcription for YouTube video', [
            'video_id' => $video->id,
            'service_url' => $transcriptionServiceUrl,
        ]);

        // The transcription service expects audio.wav in the job directory
        $response = Http::timeout(60)->post("{$transcriptionServiceUrl}/process", [
            'job_id' => (string) $video->id,
            'model_name' => 'base', // or 'medium' for better quality
            'initial_prompt' => $this->getInitialPrompt(),
        ]);

        if (!$response->successful()) {
            throw new \Exception('Transcription service error: ' . $response->body());
        }
    }

    /**
     * Get industry-specific initial prompt for Whisper
     */
    protected function getInitialPrompt(): ?string
    {
        // Music industry prompts help Whisper recognize terminology
        $prompts = [
            'truefire' => 'Guitar lesson about techniques, chords, scales, modes, fingerpicking, strumming, pentatonic, blues, jazz, rock, licks, riffs, fretboard, picking, hammer-ons, pull-offs, slides, bends, vibrato.',
            'artistworks' => 'Music lesson about technique, practice, performance, musicianship, expression, phrasing, dynamics, rhythm, melody, harmony.',
            'blayze' => 'Coaching session about performance, improvement, technique, practice, focus, goals, feedback.',
            'faderpro' => 'Music production tutorial about DAW, mixing, mastering, EQ, compression, synthesis, sampling, arrangement, beats, bass, drums.',
        ];

        return $prompts[$this->brandId] ?? $prompts['truefire'];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('YouTubeTranscriptionJob failed permanently', [
            'article_id' => $this->articleId,
            'youtube_url' => $this->youtubeUrl,
            'error' => $exception->getMessage(),
        ]);

        $article = Article::find($this->articleId);
        if ($article) {
            $article->update([
                'status' => 'error',
                'error_message' => 'YouTube transcription failed: ' . $exception->getMessage(),
            ]);
        }
    }
}
