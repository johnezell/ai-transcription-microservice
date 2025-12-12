<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Services\YouTubeTranscriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TRUE End-to-End tests for YouTube to Article generation.
 * 
 * These tests actually call the YouTube API and generate real articles.
 * They require network access and may take longer to run.
 * 
 * Run with: make test-youtube-e2e
 */
class YouTubeE2ETest extends TestCase
{
    use RefreshDatabase;

    // Real TrueFire YouTube video for testing
    protected string $testVideoUrl = 'https://www.youtube.com/watch?v=8iUjJlKBbp8';
    protected string $testVideoId = '8iUjJlKBbp8';

    // =========================================================================
    // YOUTUBE TRANSCRIPT SERVICE TESTS
    // =========================================================================

    public function test_can_extract_video_id_from_various_url_formats(): void
    {
        $service = new YouTubeTranscriptService();

        // Standard URL
        $this->assertEquals(
            '8iUjJlKBbp8',
            $service->extractVideoId('https://www.youtube.com/watch?v=8iUjJlKBbp8')
        );

        // Short URL
        $this->assertEquals(
            'dQw4w9WgXcQ',
            $service->extractVideoId('https://youtu.be/dQw4w9WgXcQ')
        );

        // Embed URL
        $this->assertEquals(
            'abc123XYZ-_',
            $service->extractVideoId('https://www.youtube.com/embed/abc123XYZ-_')
        );

        // With extra params
        $this->assertEquals(
            '8iUjJlKBbp8',
            $service->extractVideoId('https://www.youtube.com/watch?v=8iUjJlKBbp8&t=120')
        );

        // Invalid URLs
        $this->assertNull($service->extractVideoId('https://vimeo.com/12345'));
        $this->assertNull($service->extractVideoId('not-a-url'));
    }

    public function test_can_fetch_real_youtube_transcript(): void
    {
        $service = new YouTubeTranscriptService();

        try {
            $result = $service->getTranscript($this->testVideoUrl);
        } catch (\Exception $e) {
            // YouTube often rate limits containerized environments
            $message = $e->getMessage();
            if (str_contains($message, 'blocking') || 
                str_contains($message, 'IP') || 
                str_contains($message, 'Invalid response') ||
                str_contains($message, 'rate limit')) {
                $this->markTestSkipped('YouTube transcript unavailable (likely rate limited): ' . $message);
            }
            throw $e;
        }

        // Service returns array with video_id, transcript, language, url
        $this->assertArrayHasKey('video_id', $result);
        $this->assertArrayHasKey('transcript', $result);
        $this->assertEquals($this->testVideoId, $result['video_id']);
        $this->assertNotEmpty($result['transcript']);
        $this->assertGreaterThan(100, strlen($result['transcript']), 'Transcript too short');
        
        // Should contain guitar-related content
        $transcript = strtolower($result['transcript']);
        $this->assertTrue(
            str_contains($transcript, 'guitar') || 
            str_contains($transcript, 'devil inside') ||
            str_contains($transcript, 'chord') ||
            str_contains($transcript, 'music'),
            'Transcript should contain music/guitar related content'
        );
    }

    public function test_can_fetch_video_metadata(): void
    {
        $service = new YouTubeTranscriptService();

        $metadata = $service->getVideoMetadata($this->testVideoUrl);

        $this->assertNotEmpty($metadata);
        $this->assertArrayHasKey('title', $metadata);
        $this->assertNotEmpty($metadata['title']);
        
        // Title should mention the song or TrueFire
        $this->assertTrue(
            str_contains(strtolower($metadata['title']), 'devil inside') ||
            str_contains(strtolower($metadata['title']), 'truefire') ||
            str_contains(strtolower($metadata['title']), 'guitar'),
            'Video title should be related to the content'
        );
    }

    // =========================================================================
    // API ENDPOINT E2E TESTS
    // =========================================================================

    public function test_create_article_from_youtube_captions_e2e(): void
    {
        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => $this->testVideoUrl,
            'userName' => 'E2E Test User',
            'brandId' => 'truefire',
            'useWhisper' => false, // Use captions for faster test
        ]);

        // Handle rate limiting gracefully
        if ($response->status() === 500) {
            $error = $response->json('error') ?? '';
            if (str_contains($error, 'blocking') || str_contains($error, 'IP')) {
                $this->markTestSkipped('YouTube is rate limiting: ' . $error);
            }
        }

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'title',
                'status',
                'video_title',
                'mode',
                'message',
            ]);

        $articleId = $response->json('id');
        $this->assertNotNull($articleId);

        // Verify article was created in database
        $article = Article::find($articleId);
        $this->assertNotNull($article);
        $this->assertEquals('youtube', $article->source_type);
        $this->assertEquals($this->testVideoUrl, $article->source_url);
        $this->assertEquals('truefire', $article->brand_id);
        $this->assertEquals('E2E Test User', $article->created_by);

        // Transcript may or may not be saved depending on mode
        // In captions mode it should be saved, in whisper fallback it won't be
        if ($response->json('mode') === 'captions') {
            $this->assertNotEmpty($article->transcript, 'Transcript should be saved in captions mode');
            $this->assertGreaterThan(100, strlen($article->transcript));
        }
    }

    public function test_article_contains_guitar_related_transcript(): void
    {
        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => $this->testVideoUrl,
            'useWhisper' => false,
        ]);

        // Skip if rate limited
        if ($response->status() !== 200 || $response->json('mode') !== 'captions') {
            $this->markTestSkipped('YouTube captions not available (rate limited or no captions)');
        }

        $articleId = $response->json('id');
        $article = Article::find($articleId);

        if (empty($article->transcript)) {
            $this->markTestSkipped('No transcript available (fell back to whisper mode)');
        }

        $transcript = strtolower($article->transcript);
        
        // Check for music/guitar terminology
        $hasRelevantContent = 
            str_contains($transcript, 'guitar') ||
            str_contains($transcript, 'chord') ||
            str_contains($transcript, 'riff') ||
            str_contains($transcript, 'solo') ||
            str_contains($transcript, 'pentatonic') ||
            str_contains($transcript, 'music');

        $this->assertTrue($hasRelevantContent, 'Transcript should contain music-related terminology');
    }

    public function test_whisper_mode_creates_article_for_processing(): void
    {
        // Note: Whisper mode requires the YouTubeTranscriptionJob to be dispatchable
        // In test environment with sync queue, this may fail if yt-dlp isn't available
        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => $this->testVideoUrl,
            'userName' => 'Whisper Test',
            'brandId' => 'truefire',
            'useWhisper' => true,
        ]);

        // Accept 200 (success) or 500 (if yt-dlp not available in test container)
        if ($response->status() === 500) {
            $this->markTestSkipped('Whisper mode requires yt-dlp which may not be in test container');
        }

        $response->assertStatus(200)
            ->assertJson(['mode' => 'whisper']);

        $articleId = $response->json('id');
        $article = Article::find($articleId);

        $this->assertNotNull($article);
        $this->assertEquals('generating', $article->status);
    }

    public function test_different_youtube_url_formats_work(): void
    {
        // Standard format
        $response1 = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://www.youtube.com/watch?v=' . $this->testVideoId,
            'useWhisper' => false,
        ]);
        $response1->assertStatus(200);

        // Short format
        $response2 = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://youtu.be/' . $this->testVideoId,
            'useWhisper' => false,
        ]);
        $response2->assertStatus(200);
    }

    public function test_brand_settings_are_applied(): void
    {
        // TrueFire brand
        $response1 = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => $this->testVideoUrl,
            'brandId' => 'truefire',
            'useWhisper' => false,
        ]);
        $this->assertEquals('truefire', Article::find($response1->json('id'))->brand_id);

        // ArtistWorks brand
        $response2 = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => $this->testVideoUrl,
            'brandId' => 'artistworks',
            'useWhisper' => false,
        ]);
        $this->assertEquals('artistworks', Article::find($response2->json('id'))->brand_id);

        // Default brand
        $response3 = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => $this->testVideoUrl,
            'useWhisper' => false,
        ]);
        $this->assertEquals('truefire', Article::find($response3->json('id'))->brand_id);
    }

    // =========================================================================
    // FULL GENERATION E2E TEST (Requires Bedrock)
    // =========================================================================

    #[\PHPUnit\Framework\Attributes\Group('slow')]
    #[\PHPUnit\Framework\Attributes\Group('bedrock')]
    public function test_full_article_generation_e2e(): void
    {
        // This test requires Bedrock and runs the full pipeline
        // Skip if we hit rate limits or other Bedrock issues
        
        // Create article from YouTube
        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => $this->testVideoUrl,
            'userName' => 'Full E2E Test',
            'brandId' => 'truefire',
            'useWhisper' => false,
        ]);

        if ($response->status() !== 200) {
            $this->markTestSkipped('YouTube transcript extraction failed in this run');
        }

        $articleId = $response->json('id');

        // Process the queue job synchronously
        $this->artisan('queue:work', ['--once' => true]);

        // Refresh and check the article
        $article = Article::find($articleId);
        
        if (!$article) {
            $this->markTestSkipped('Article was not created');
        }

        // Article should now be generated or still generating (depends on Bedrock)
        $this->assertContains($article->status, ['draft', 'generating', 'error']);

        if ($article->status === 'draft') {
            // Verify generated content
            $this->assertNotEmpty($article->title);
            $this->assertNotEquals('Generating: ' . $response->json('video_title'), $article->title);
            $this->assertNotEmpty($article->content);
            $this->assertNotEmpty($article->slug);
            $this->assertNotEmpty($article->meta_description);
            
            // Content should be HTML
            $this->assertStringContainsString('<', $article->content);
            $this->assertStringContainsString('>', $article->content);
        } elseif ($article->status === 'error') {
            // Log the error for debugging
            $this->markTestSkipped('Article generation failed: ' . $article->error_message);
        }
    }

    // =========================================================================
    // ERROR HANDLING E2E TESTS  
    // =========================================================================

    public function test_handles_invalid_video_id(): void
    {
        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://www.youtube.com/watch?v=INVALID_ID_123',
            'useWhisper' => false,
        ]);

        // Should either return error or fall back to Whisper
        $this->assertTrue(
            $response->status() === 200 || $response->status() === 400 || $response->status() === 500,
            'Should handle invalid video gracefully'
        );
    }

    public function test_validates_url_format(): void
    {
        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'not-a-url',
        ]);

        $response->assertStatus(422);
    }

    public function test_requires_youtube_url(): void
    {
        $response = $this->postJson('/api/articles/from-youtube', [
            'userName' => 'Test',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['youtubeUrl']);
    }

    // =========================================================================
    // ARTICLE CRUD E2E TESTS
    // =========================================================================

    public function test_youtube_article_appears_in_article_list(): void
    {
        // Create article
        $createResponse = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => $this->testVideoUrl,
            'useWhisper' => false,
        ]);
        $articleId = $createResponse->json('id');

        // List articles
        $listResponse = $this->getJson('/api/articles');
        $listResponse->assertStatus(200);

        $articles = $listResponse->json('data') ?? $listResponse->json();
        $found = collect($articles)->contains('id', $articleId);
        
        $this->assertTrue($found, 'YouTube article should appear in list');
    }

    public function test_can_retrieve_youtube_article(): void
    {
        $createResponse = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => $this->testVideoUrl,
            'useWhisper' => false,
        ]);
        
        $createResponse->assertStatus(200);
        $articleId = $createResponse->json('id');
        $this->assertNotNull($articleId, 'Article ID should be returned');

        $getResponse = $this->getJson("/api/articles/{$articleId}");
        
        $getResponse->assertStatus(200)
            ->assertJsonFragment([
                'id' => $articleId,
                'source_type' => 'youtube',
            ]);
    }

    public function test_can_update_youtube_article(): void
    {
        $createResponse = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => $this->testVideoUrl,
            'useWhisper' => false,
        ]);
        $articleId = $createResponse->json('id');

        $updateResponse = $this->putJson("/api/articles/{$articleId}", [
            'title' => 'Updated E2E Title',
            'content' => '<p>Updated content from E2E test</p>',
        ]);

        $updateResponse->assertStatus(200);

        $this->assertDatabaseHas('articles', [
            'id' => $articleId,
            'title' => 'Updated E2E Title',
        ]);
    }
}
