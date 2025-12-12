<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class YouTubeTranscriptService
{
    /**
     * Extract video ID from various YouTube URL formats
     */
    public function extractVideoId(string $url): ?string
    {
        $patterns = [
            '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/v\/)([a-zA-Z0-9_-]{11})/',
            '/^([a-zA-Z0-9_-]{11})$/', // Just the video ID
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Fetch transcript from YouTube video using Python script
     */
    public function getTranscript(string $youtubeUrl): array
    {
        $videoId = $this->extractVideoId($youtubeUrl);
        
        if (!$videoId) {
            throw new \Exception('Invalid YouTube URL: Could not extract video ID');
        }

        Log::info('Fetching YouTube transcript via Python', ['video_id' => $videoId]);

        $scriptPath = base_path('scripts/youtube_transcript.py');
        
        // Run the Python script
        $result = Process::timeout(60)->run("python3 {$scriptPath} {$videoId}");
        
        if (!$result->successful()) {
            // Try with 'python' instead of 'python3'
            $result = Process::timeout(60)->run("python {$scriptPath} {$videoId}");
        }

        $output = $result->output();
        
        try {
            $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::error('Failed to parse Python script output', [
                'output' => $output,
                'error' => $result->errorOutput(),
            ]);
            throw new \Exception('Failed to fetch YouTube transcript: Invalid response from transcript service');
        }

        if (!($data['success'] ?? false)) {
            $error = $data['error'] ?? 'Unknown error fetching transcript';
            Log::error('YouTube transcript extraction failed', [
                'video_id' => $videoId,
                'error' => $error,
            ]);
            throw new \Exception($error);
        }

        Log::info('YouTube transcript fetched successfully', [
            'video_id' => $videoId,
            'transcript_length' => strlen($data['transcript'] ?? ''),
            'language' => $data['language'] ?? 'unknown',
        ]);

        return [
            'video_id' => $data['video_id'],
            'transcript' => $data['transcript'],
            'language' => $data['language'] ?? null,
            'url' => $youtubeUrl,
        ];
    }

    /**
     * Get video metadata (title, description, etc.)
     */
    public function getVideoMetadata(string $youtubeUrl): array
    {
        $videoId = $this->extractVideoId($youtubeUrl);
        
        if (!$videoId) {
            throw new \Exception('Invalid YouTube URL');
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ])->timeout(10)->get("https://www.youtube.com/watch?v={$videoId}");

            $html = $response->body();
            
            $metadata = [
                'video_id' => $videoId,
                'title' => null,
                'description' => null,
                'channel' => null,
            ];

            // Extract title
            if (preg_match('/<title>(.+?)<\/title>/', $html, $matches)) {
                $metadata['title'] = html_entity_decode(str_replace(' - YouTube', '', $matches[1]));
            }

            // Extract channel name
            if (preg_match('/"ownerChannelName":\s*"([^"]+)"/', $html, $matches)) {
                $metadata['channel'] = $matches[1];
            }

            return $metadata;
        } catch (\Exception $e) {
            Log::warning('Failed to fetch video metadata', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'video_id' => $videoId,
                'title' => null,
                'channel' => null,
            ];
        }
    }
}
