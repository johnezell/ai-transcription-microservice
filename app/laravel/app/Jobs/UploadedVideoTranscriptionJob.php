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

/**
 * Process an uploaded video for article generation.
 * 
 * 1. Extract audio from video using ffmpeg
 * 2. Trigger Whisper transcription with industry-specific prompts
 * 3. Article generation is triggered by TranscriptionController callback
 */
class UploadedVideoTranscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 minutes
    public int $tries = 2;

    public string $videoId;
    public int $articleId;
    public string $brandId;

    public function __construct(
        string $videoId,
        int $articleId,
        string $brandId = 'truefire'
    ) {
        $this->videoId = $videoId;
        $this->articleId = $articleId;
        $this->brandId = $brandId;
    }

    public function handle(): void
    {
        Log::info('Starting uploaded video transcription job', [
            'video_id' => $this->videoId,
            'article_id' => $this->articleId,
            'brand_id' => $this->brandId,
        ]);

        $video = Video::find($this->videoId);
        if (!$video) {
            Log::error('Video not found for transcription', ['video_id' => $this->videoId]);
            $this->markArticleError('Video not found');
            return;
        }

        try {
            // Step 1: Extract audio from video
            $audioPath = $this->extractAudio($video);
            
            // Update video with audio path
            $video->update([
                'audio_path' => $audioPath,
                'status' => 'extracting_audio',
            ]);

            // Step 2: Trigger transcription service
            $this->triggerTranscription($video, $audioPath);

            Log::info('Video transcription triggered successfully', [
                'video_id' => $this->videoId,
                'article_id' => $this->articleId,
            ]);

        } catch (\Exception $e) {
            Log::error('Uploaded video transcription failed', [
                'video_id' => $this->videoId,
                'article_id' => $this->articleId,
                'error' => $e->getMessage(),
            ]);

            $video->update([
                'status' => 'failed',
                'metadata' => array_merge($video->metadata ?? [], [
                    'error_message' => $e->getMessage(),
                ]),
            ]);

            $this->markArticleError($e->getMessage());
        }
    }

    /**
     * Extract audio from video file using ffmpeg
     */
    protected function extractAudio(Video $video): string
    {
        $videoPath = $video->storage_path;
        
        if (!file_exists($videoPath)) {
            throw new \Exception("Video file not found: {$videoPath}");
        }

        $jobDir = dirname($videoPath);
        $audioPath = "{$jobDir}/audio.wav";

        Log::info('Extracting audio from video', [
            'video_path' => $videoPath,
            'audio_path' => $audioPath,
        ]);

        // Extract audio with ffmpeg (16kHz mono for Whisper)
        $result = Process::timeout(300)->run(
            "ffmpeg -y -i \"{$videoPath}\" -ar 16000 -ac 1 -acodec pcm_s16le \"{$audioPath}\" 2>&1"
        );

        if (!$result->successful() || !file_exists($audioPath)) {
            throw new \Exception('Failed to extract audio: ' . $result->output());
        }

        Log::info('Audio extracted successfully', [
            'audio_path' => $audioPath,
            'audio_size' => filesize($audioPath),
        ]);

        return $audioPath;
    }

    /**
     * Trigger transcription via the transcription service
     */
    protected function triggerTranscription(Video $video, string $audioPath): void
    {
        $transcriptionServiceUrl = env('TRANSCRIPTION_SERVICE_URL', 'http://transcription-service:5000');

        // Use the transcription_job_id from metadata (the directory name where files are stored)
        // This matches the path structure: /var/www/storage/app/public/s3/jobs/{job_id}/audio.wav
        $transcriptionJobId = $video->metadata['transcription_job_id'] ?? (string) $video->id;

        Log::info('Triggering Whisper transcription', [
            'video_id' => $video->id,
            'transcription_job_id' => $transcriptionJobId,
            'service_url' => $transcriptionServiceUrl,
        ]);

        // Increase timeout for longer videos - transcription service processes asynchronously
        // and calls back when done, but we need time for the initial handshake
        $response = Http::timeout(300)->post("{$transcriptionServiceUrl}/process", [
            'job_id' => $transcriptionJobId,
            'model_name' => 'base',
            'initial_prompt' => $this->getInitialPrompt(),
        ]);

        if (!$response->successful()) {
            throw new \Exception('Transcription service error: ' . $response->body());
        }

        $video->update(['status' => 'transcribing']);
    }

    /**
     * Get industry-specific initial prompt for Whisper
     */
    protected function getInitialPrompt(): ?string
    {
        $prompts = [
            'truefire' => 'Guitar lesson about techniques, chords, scales, modes, fingerpicking, strumming, pentatonic, blues, jazz, rock, licks, riffs, fretboard, picking, hammer-ons, pull-offs, slides, bends, vibrato.',
            'artistworks' => 'Music lesson about technique, practice, performance, musicianship, expression, phrasing, dynamics, rhythm, melody, harmony.',
            'blayze' => 'Coaching session about performance, improvement, technique, practice, focus, goals, feedback.',
            'faderpro' => 'Music production tutorial about DAW, mixing, mastering, EQ, compression, synthesis, sampling, arrangement, beats, bass, drums.',
        ];

        return $prompts[$this->brandId] ?? $prompts['truefire'];
    }

    /**
     * Mark the article as having an error
     */
    protected function markArticleError(string $message): void
    {
        $article = Article::find($this->articleId);
        if ($article) {
            $article->update([
                'status' => 'error',
                'error_message' => 'Transcription failed: ' . $message,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('UploadedVideoTranscriptionJob failed permanently', [
            'video_id' => $this->videoId,
            'article_id' => $this->articleId,
            'error' => $exception->getMessage(),
        ]);

        $this->markArticleError($exception->getMessage());
    }
}
