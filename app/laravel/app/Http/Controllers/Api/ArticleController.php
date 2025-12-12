<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateArticleJob;
use App\Models\Article;
use App\Models\ArticleComment;
use App\Models\BrandSetting;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ArticleController extends Controller
{
    /**
     * List all articles (with pagination)
     */
    public function index(Request $request): JsonResponse
    {
        $brandId = $request->query('brandId');
        $page = max(1, (int) $request->query('page', 1));
        $limit = min(100, max(1, (int) $request->query('limit', 12)));

        $query = Article::query();

        if ($brandId) {
            $query->forBrand($brandId);
        }

        $total = $query->count();
        $totalPages = ceil($total / $limit);

        $articles = $query
            ->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get(['id', 'title', 'status', 'source_type', 'brand_id', 'created_by', 'created_at', 'updated_at']);

        return response()->json([
            'data' => $articles,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1,
            ],
        ]);
    }

    /**
     * Get a single article
     */
    public function show(int $id): JsonResponse
    {
        $article = Article::find($id);

        if (!$article) {
            return response()->json(['error' => 'Article not found'], 404);
        }

        return response()->json($article);
    }

    /**
     * Create article from YouTube URL (extracts transcript and generates)
     * 
     * Two modes:
     * 1. Quick mode (useWhisper=false): Use YouTube's existing captions
     * 2. Full mode (useWhisper=true): Download audio and transcribe with Whisper
     *    - Uses industry-specific initial prompts
     *    - Better quality for music terminology
     *    - Triggers full Thoth pipeline
     */
    public function createFromYoutube(Request $request): JsonResponse
    {
        $request->validate([
            'youtubeUrl' => 'required|url',
            'userName' => 'nullable|string|max:255',
            'brandId' => 'nullable|string|max:50',
            'useWhisper' => 'nullable|boolean',
        ]);

        $youtubeUrl = $request->input('youtubeUrl');
        $userName = $request->input('userName', 'Anonymous');
        $brandId = $request->input('brandId', 'truefire');
        $useWhisper = $request->input('useWhisper', true); // Default to Whisper for quality

        try {
            // Get video metadata
            $youtubeService = new \App\Services\YouTubeTranscriptService();
            $metadata = $youtubeService->getVideoMetadata($youtubeUrl);
            $videoId = $youtubeService->extractVideoId($youtubeUrl);

            if (!$videoId) {
                return response()->json(['error' => 'Invalid YouTube URL'], 400);
            }

            Log::info('Processing YouTube video', [
                'video_id' => $videoId,
                'title' => $metadata['title'] ?? 'Unknown',
                'use_whisper' => $useWhisper,
                'brand_id' => $brandId,
            ]);

            if ($useWhisper) {
                // Full Whisper pipeline - better quality, industry-specific
                return $this->createWithWhisperTranscription(
                    $youtubeUrl, 
                    $videoId, 
                    $metadata, 
                    $userName, 
                    $brandId
                );
            } else {
                // Quick mode - use YouTube captions if available
                return $this->createWithYouTubeCaptions(
                    $youtubeUrl, 
                    $videoId, 
                    $metadata, 
                    $userName, 
                    $brandId, 
                    $youtubeService
                );
            }

        } catch (\Exception $e) {
            Log::error('Failed to create article from YouTube', [
                'url' => $youtubeUrl,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create article using Whisper transcription (full pipeline)
     */
    protected function createWithWhisperTranscription(
        string $youtubeUrl,
        string $videoId,
        array $metadata,
        string $userName,
        string $brandId
    ): JsonResponse {
        // Create article record - will be updated after transcription completes
        // Use 'generating' status since that's what the enum allows
        $article = Article::create([
            'title' => 'Processing: ' . ($metadata['title'] ?? 'YouTube Video'),
            'content' => '<p>Downloading and transcribing video with Whisper. This may take 3-5 minutes for optimal quality...</p>',
            'source_type' => 'youtube',
            'source_url' => $youtubeUrl,
            'status' => 'generating', // Will be updated to 'draft' when complete
            'brand_id' => $brandId,
            'created_by' => $userName,
        ]);

        // Dispatch the YouTube transcription job
        // This will: download audio → Whisper transcribe → generate article
        \App\Jobs\YouTubeTranscriptionJob::dispatch(
            $youtubeUrl,
            $article->id,
            $brandId,
            $metadata
        );

        return response()->json([
            'id' => $article->id,
            'title' => $article->title,
            'status' => $article->status,
            'video_title' => $metadata['title'] ?? null,
            'mode' => 'whisper',
            'message' => 'Video is being transcribed with Whisper for optimal quality. This takes 3-5 minutes.',
        ]);
    }

    /**
     * Create article using YouTube's existing captions (quick mode)
     */
    protected function createWithYouTubeCaptions(
        string $youtubeUrl,
        string $videoId,
        array $metadata,
        string $userName,
        string $brandId,
        \App\Services\YouTubeTranscriptService $youtubeService
    ): JsonResponse {
        try {
            $result = $youtubeService->getTranscript($youtubeUrl);
            $transcript = $result['transcript'];

            if (empty($transcript) || strlen($transcript) < 100) {
                // Fall back to Whisper if no captions available
                Log::info('No YouTube captions, falling back to Whisper', ['video_id' => $videoId]);
                return $this->createWithWhisperTranscription(
                    $youtubeUrl, $videoId, $metadata, $userName, $brandId
                );
            }

            // Create article with caption-based transcript
            $article = Article::create([
                'title' => 'Generating: ' . ($metadata['title'] ?? 'YouTube Video'),
                'content' => '<p>Your article is being generated. This may take 1-2 minutes...</p>',
                'source_type' => 'youtube',
                'source_url' => $youtubeUrl,
                'transcript' => $transcript,
                'status' => 'generating',
                'brand_id' => $brandId,
                'created_by' => $userName,
            ]);

            GenerateArticleJob::dispatch($article->id, $transcript, $brandId);

            return response()->json([
                'id' => $article->id,
                'title' => $article->title,
                'status' => $article->status,
                'video_title' => $metadata['title'] ?? null,
                'mode' => 'captions',
                'message' => 'Using YouTube captions for quick processing.',
            ]);

        } catch (\Exception $e) {
            // If caption extraction fails, fall back to Whisper
            Log::warning('Caption extraction failed, using Whisper', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);
            return $this->createWithWhisperTranscription(
                $youtubeUrl, $videoId, $metadata, $userName, $brandId
            );
        }
    }

    /**
     * Create article from an existing Thoth video transcript
     */
    public function createFromVideo(Request $request): JsonResponse
    {
        $request->validate([
            'videoId' => 'required|string', // UUID format
            'userName' => 'nullable|string|max:255',
            'brandId' => 'nullable|string|max:50',
        ]);

        $videoId = $request->input('videoId');
        $userName = $request->input('userName', 'Anonymous');
        $brandId = $request->input('brandId', 'truefire');

        // Find the video
        $video = Video::find($videoId);
        if (!$video) {
            return response()->json(['error' => 'Video not found'], 404);
        }

        // Check if video has a transcript
        $transcript = $video->transcript_text ?? null;
        if (empty($transcript)) {
            // Try to load from file
            if (!empty($video->transcript_path) && file_exists($video->transcript_path)) {
                $transcript = file_get_contents($video->transcript_path);
            }
        }

        if (empty($transcript)) {
            return response()->json(['error' => 'Video does not have a transcript'], 400);
        }

        // Create article record with placeholder content
        $article = Article::create([
            'title' => 'Generating article...',
            'content' => '<p>Your article is being generated. This may take 1-2 minutes...</p>',
            'source_type' => 'transcript',
            'transcript' => $transcript,
            'video_id' => $videoId,
            'status' => 'generating',
            'brand_id' => $brandId,
            'created_by' => $userName,
        ]);

        // Dispatch the generation job
        GenerateArticleJob::dispatch($article->id, $transcript, $brandId);

        return response()->json([
            'id' => $article->id,
            'title' => $article->title,
            'status' => $article->status,
        ]);
    }

    /**
     * Create article from raw transcript text
     */
    public function createFromTranscript(Request $request): JsonResponse
    {
        $request->validate([
            'transcript' => 'required|string|min:100',
            'userName' => 'nullable|string|max:255',
            'brandId' => 'nullable|string|max:50',
            'sourceUrl' => 'nullable|url',
        ]);

        $transcript = $request->input('transcript');
        $userName = $request->input('userName', 'Anonymous');
        $brandId = $request->input('brandId', 'truefire');
        $sourceUrl = $request->input('sourceUrl');

        // Create article record with placeholder content
        $article = Article::create([
            'title' => 'Generating article...',
            'content' => '<p>Your article is being generated. This may take 1-2 minutes...</p>',
            'source_type' => 'transcript',
            'source_url' => $sourceUrl,
            'transcript' => $transcript,
            'status' => 'generating',
            'brand_id' => $brandId,
            'created_by' => $userName,
        ]);

        // Dispatch the generation job
        GenerateArticleJob::dispatch($article->id, $transcript, $brandId);

        return response()->json([
            'id' => $article->id,
            'title' => $article->title,
            'status' => $article->status,
        ]);
    }

    /**
     * Create article from uploaded video file
     * 
     * Uploads the video, triggers Whisper transcription with industry settings,
     * then generates an article from the transcript.
     */
    public function createFromUpload(Request $request): JsonResponse
    {
        $request->validate([
            'video' => 'required|file|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm|max:512000', // 500MB
            'userName' => 'nullable|string|max:255',
            'brandId' => 'nullable|string|max:50',
        ]);

        $userName = $request->input('userName', 'Anonymous');
        $brandId = $request->input('brandId', 'truefire');

        try {
            $file = $request->file('video');
            $originalFilename = $file->getClientOriginalName();

            Log::info('Processing uploaded video for article generation', [
                'filename' => $originalFilename,
                'size' => $file->getSize(),
                'brand_id' => $brandId,
            ]);

            // Create a unique job directory (use local storage for video processing)
            $jobId = uniqid('upload-');
            $jobDir = storage_path("app/public/s3/jobs/{$jobId}");
            
            if (!is_dir($jobDir)) {
                mkdir($jobDir, 0755, true);
            }

            // Store the video file locally (not S3) for transcription processing
            $videoFilename = 'video.mp4';
            $file->move($jobDir, $videoFilename);
            $videoPath = "{$jobDir}/{$videoFilename}";

            Log::info('Video file stored locally for processing', [
                'job_id' => $jobId,
                'path' => $videoPath,
                'exists' => file_exists($videoPath),
            ]);

            // Create Video record - store job_id for transcription service path lookup
            $video = Video::create([
                'original_filename' => $originalFilename,
                'mime_type' => $file->getClientMimeType() ?? 'video/mp4',
                'size_bytes' => filesize($videoPath),
                'status' => 'processing',
                'storage_path' => $videoPath,
                'metadata' => [
                    'source' => 'upload',
                    'brand_id' => $brandId,
                    'uploaded_for_article' => true,
                    'transcription_job_id' => $jobId, // Used by transcription service to find files
                ],
            ]);

            // Create article record - will be updated after transcription completes
            $article = Article::create([
                'title' => 'Processing: ' . pathinfo($originalFilename, PATHINFO_FILENAME),
                'content' => '<p>Your video is being transcribed with Whisper. This may take 3-5 minutes...</p>',
                'source_type' => 'video',
                'source_file' => $originalFilename,
                'video_id' => $video->id,
                'status' => 'generating',
                'brand_id' => $brandId,
                'created_by' => $userName,
            ]);

            // Create transcription log - use the directory name as job_id for transcription service lookup
            \App\Models\TranscriptionLog::create([
                'video_id' => $video->id,
                'job_id' => $jobId, // The directory name (upload-xxx) where files are stored
                'status' => 'processing',
                'started_at' => now(),
            ]);

            // Extract audio and trigger transcription
            \App\Jobs\UploadedVideoTranscriptionJob::dispatch(
                $video->id,
                $article->id,
                $brandId
            );

            return response()->json([
                'id' => $article->id,
                'title' => $article->title,
                'status' => $article->status,
                'video_id' => $video->id,
                'message' => 'Video uploaded. Transcribing with Whisper...',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process uploaded video', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create article from TrueFire segment
     * 
     * Accepts either a segment ID directly or parses it from a TrueFire URL.
     * Looks up the video in the TrueFire database, downloads the MP4 from
     * CloudFront, and triggers Whisper transcription.
     * 
     * Input formats accepted:
     *   - segmentId: "2228" (just the ID)
     *   - segmentId: "v2228" (with v prefix)
     *   - sourceUrl: "https://truefire.com/.../v2228" (full URL)
     */
    public function createFromTruefire(Request $request): JsonResponse
    {
        $request->validate([
            'segmentId' => 'required|string',
            'sourceUrl' => 'nullable|string',
            'userName' => 'nullable|string|max:255',
            'brandId' => 'nullable|string|max:50',
        ]);

        $input = $request->input('segmentId');
        $sourceUrl = $request->input('sourceUrl');
        $userName = $request->input('userName', 'Thoth User');
        $brandId = $request->input('brandId', 'truefire');

        // Parse segment ID from various input formats
        $segmentId = null;
        
        // Just a number
        if (preg_match('/^\d+$/', $input)) {
            $segmentId = (int) $input;
        }
        // "v####" format
        elseif (preg_match('/^v(\d+)$/i', $input, $matches)) {
            $segmentId = (int) $matches[1];
        }
        // URL format - extract from path
        elseif (preg_match('/\/v(\d+)(?:$|[?#\/])/', $input, $matches)) {
            $segmentId = (int) $matches[1];
            $sourceUrl = $sourceUrl ?: $input;
        }

        if (!$segmentId) {
            return response()->json([
                'error' => 'Invalid segment ID format. Expected: 2228, v2228, or https://truefire.com/.../v2228'
            ], 422);
        }

        try {
            // Look up the segment in TrueFire database
            $segment = \App\Models\TrueFire\TrueFireSegment::find($segmentId);

            if (!$segment) {
                return response()->json(['error' => "Segment {$segmentId} not found"], 404);
            }

            // Check if we already have a transcription for this segment (from batch processing)
            $existingVideo = Video::whereRaw("json_extract(metadata, '$.truefire_segment_id') = ?", [$segmentId])
                ->whereNotNull('transcript_text')
                ->where('transcript_text', '!=', '')
                ->first();

            if ($existingVideo) {
                Log::info('Found existing transcription for TrueFire segment', [
                    'segment_id' => $segmentId,
                    'video_id' => $existingVideo->id,
                    'transcript_length' => strlen($existingVideo->transcript_text),
                ]);

                // Create article directly from existing transcript
                $article = Article::create([
                    'title' => $segment->name ?? "TrueFire Segment {$segmentId}",
                    'content' => '<p>Ready for article generation from existing transcript.</p>',
                    'source_type' => 'video',
                    'source_url' => $sourceUrl ?? "https://truefire.com/v{$segmentId}",
                    'source_file' => $segment->name,
                    'video_id' => $existingVideo->id,
                    'transcript' => $existingVideo->transcript_text,
                    'status' => 'draft', // Ready for Phase 2 article generation
                    'brand_id' => $brandId,
                    'created_by' => $userName,
                ]);

                return response()->json([
                    'id' => $article->id,
                    'title' => $article->title,
                    'status' => $article->status,
                    'video_id' => $existingVideo->id,
                    'segment_id' => $segmentId,
                    'transcript_length' => strlen($existingVideo->transcript_text),
                    'message' => 'Found existing transcription. Article ready for generation.',
                ]);
            }

            // No existing transcription - queue the download and transcription
            $s3Key = $segment->getS3Key(\App\Models\TrueFire\TrueFireSegment::QUALITY_HI);
            $s3Bucket = \App\Models\TrueFire\TrueFireSegment::S3_BUCKET;

            if (!$s3Key) {
                return response()->json(['error' => 'Segment has no video file'], 400);
            }

            $jobId = uniqid('truefire-');

            Log::info('Queuing TrueFire segment for download and transcription', [
                'segment_id' => $segmentId,
                'segment_name' => $segment->name,
                's3_bucket' => $s3Bucket,
                's3_key' => $s3Key,
                'job_id' => $jobId,
                'brand_id' => $brandId,
            ]);

            // Create Video record immediately (will be updated when download completes)
            $video = Video::create([
                'original_filename' => $segment->name ?? "Segment {$segmentId}",
                'mime_type' => 'video/mp4',
                'size_bytes' => 0, // Will be updated after download
                'status' => 'downloading',
                's3_key' => $s3Key,
                'metadata' => [
                    'source' => 'truefire',
                    'brand_id' => $brandId,
                    'truefire_segment_id' => $segmentId,
                    'truefire_source_url' => $sourceUrl,
                    's3_bucket' => $s3Bucket,
                    's3_key' => $s3Key,
                    'transcription_job_id' => $jobId,
                ],
            ]);

            // Create article record immediately so user can see progress
            $article = Article::create([
                'title' => 'Downloading: ' . ($segment->name ?? "TrueFire Segment {$segmentId}"),
                'content' => '<p>Downloading video from TrueFire... This may take 1-2 minutes for longer videos.</p>',
                'source_type' => 'video',
                'source_url' => $sourceUrl ?? "https://truefire.com/v{$segmentId}",
                'source_file' => $segment->name,
                'video_id' => $video->id,
                'status' => 'generating',
                'brand_id' => $brandId,
                'created_by' => $userName,
            ]);

            // Create transcription log
            \App\Models\TranscriptionLog::create([
                'video_id' => $video->id,
                'job_id' => $jobId,
                'status' => 'downloading',
                'started_at' => now(),
            ]);

            // Dispatch job to download and transcribe asynchronously
            \App\Jobs\TrueFireDownloadJob::dispatch(
                $video->id,
                $article->id,
                $segmentId,
                $s3Bucket,
                $s3Key,
                $jobId,
                $brandId
            );

            return response()->json([
                'id' => $article->id,
                'title' => $article->title,
                'status' => $article->status,
                'video_id' => $video->id,
                'segment_id' => $segmentId,
                'message' => 'Processing started. You can track progress on the article page.',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process TrueFire segment', [
                'segment_id' => $segmentId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Download a video from S3 to a local path
     * 
     * Uses a pre-signed URL to download via cURL to avoid SDK content-encoding issues.
     */
    protected function downloadFromS3(string $bucket, string $key, string $destPath): void
    {
        Log::info('Downloading from S3', [
            'bucket' => $bucket,
            'key' => $key,
            'dest_path' => $destPath,
        ]);

        // Create S3 client with the TrueFire profile credentials
        $s3Client = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'http' => [
                'decode_content' => false, // Disable content decoding
            ],
        ]);

        try {
            // Generate a pre-signed URL valid for 1 hour
            $cmd = $s3Client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key' => $key,
            ]);
            
            $presignedUrl = (string) $s3Client->createPresignedRequest($cmd, '+1 hour')->getUri();
            
            Log::info('Generated pre-signed URL for S3 download', [
                'bucket' => $bucket,
                'key' => $key,
            ]);

            // Download using cURL - use HTTP_VERSION_1_1 to avoid encoding issues
            $ch = curl_init($presignedUrl);
            $fp = fopen($destPath, 'wb');
            
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept-Encoding: identity', // Request no encoding
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

        } catch (\Aws\Exception\AwsException $e) {
            throw new \Exception("Failed to access S3: " . $e->getMessage());
        }
    }

    /**
     * Download a video from a URL to a local path
     */
    protected function downloadVideo(string $url, string $destPath): void
    {
        $ch = curl_init($url);
        $fp = fopen($destPath, 'wb');
        
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600); // 10 minutes
        
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        fclose($fp);
        
        if (!$success || $httpCode !== 200) {
            unlink($destPath);
            throw new \Exception("Failed to download video: HTTP {$httpCode} - {$error}");
        }
    }

    /**
     * Update an article
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $article = Article::find($id);

        if (!$article) {
            return response()->json(['error' => 'Article not found'], 404);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:500',
            'content' => 'nullable|string',
            'author' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:200',
            'slug' => 'nullable|string|max:255',
            'status' => 'nullable|in:draft,published,archived',
        ]);

        // Ensure slug is unique if changed
        if (isset($validated['slug']) && $validated['slug'] !== $article->slug) {
            $validated['slug'] = Article::generateSlug($validated['slug'], $id);
        }

        $article->update(array_filter($validated, fn($v) => $v !== null));

        return response()->json(['message' => 'Article updated successfully']);
    }

    /**
     * Delete an article (soft delete)
     */
    public function destroy(int $id): JsonResponse
    {
        $article = Article::find($id);

        if (!$article) {
            return response()->json(['error' => 'Article not found'], 404);
        }

        $article->delete();

        return response()->json(['message' => 'Article deleted successfully']);
    }

    /**
     * Get brand settings
     */
    public function getSettings(Request $request): JsonResponse
    {
        $brandId = $request->query('brandId', 'truefire');
        $settings = BrandSetting::getForBrand($brandId);

        // Add defaults if not set
        if (!$settings->has('llm_model')) {
            $settings['llm_model'] = BrandSetting::getLlmModel($brandId);
        }
        if (!$settings->has('system_prompt')) {
            $settings['system_prompt'] = BrandSetting::getSystemPrompt($brandId);
        }

        return response()->json([
            'brandId' => $brandId,
            'settings' => $settings,
            'availableBrands' => BrandSetting::BRANDS,
        ]);
    }

    /**
     * Update brand settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brandId' => 'required|string|max:50',
            'llm_model' => 'nullable|string|max:100',
            'system_prompt' => 'nullable|string|max:5000',
        ]);

        $brandId = $validated['brandId'];

        if (isset($validated['llm_model'])) {
            BrandSetting::set($brandId, 'llm_model', $validated['llm_model']);
        }

        if (isset($validated['system_prompt'])) {
            BrandSetting::set($brandId, 'system_prompt', $validated['system_prompt']);
        }

        return response()->json(['message' => 'Settings updated successfully']);
    }

    /**
     * Get comments for an article
     */
    public function getComments(int $articleId): JsonResponse
    {
        $article = Article::find($articleId);

        if (!$article) {
            return response()->json(['error' => 'Article not found'], 404);
        }

        $comments = $article->comments()->with('replies')->get();

        return response()->json($comments);
    }

    /**
     * Add a comment to an article
     */
    public function addComment(Request $request, int $articleId): JsonResponse
    {
        $article = Article::find($articleId);

        if (!$article) {
            return response()->json(['error' => 'Article not found'], 404);
        }

        $validated = $request->validate([
            'user_name' => 'required|string|max:255',
            'content' => 'required|string|max:2000',
            'selection_text' => 'nullable|string|max:500',
            'position_start' => 'nullable|integer|min:0',
            'position_end' => 'nullable|integer|min:0',
            'parent_id' => 'nullable|exists:article_comments,id',
        ]);

        $validated['article_id'] = $articleId;

        // Set thread_id for replies
        if (!empty($validated['parent_id'])) {
            $parent = ArticleComment::find($validated['parent_id']);
            $validated['thread_id'] = $parent->thread_id ?? $parent->id;
        }

        $comment = ArticleComment::create($validated);

        return response()->json($comment, 201);
    }

    /**
     * Delete a comment
     */
    public function deleteComment(int $articleId, int $commentId): JsonResponse
    {
        $comment = ArticleComment::where('article_id', $articleId)
            ->where('id', $commentId)
            ->first();

        if (!$comment) {
            return response()->json(['error' => 'Comment not found'], 404);
        }

        $comment->delete();

        return response()->json(['message' => 'Comment deleted successfully']);
    }
}


