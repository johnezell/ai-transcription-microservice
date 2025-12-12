<?php

namespace Tests\Feature;

use App\Jobs\GenerateArticleJob;
use App\Jobs\YouTubeTranscriptionJob;
use App\Models\Article;
use App\Models\BrandSetting;
use App\Services\YouTubeTranscriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * End-to-End tests for YouTube to Article generation flow.
 * 
 * Tests cover:
 * - YouTube URL validation
 * - Transcript extraction (mocked)
 * - Article creation from captions
 * - Article creation with Whisper pipeline
 * - Error handling
 * - Brand-specific settings
 */
class YouTubeArticleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    // =========================================================================
    // URL VALIDATION TESTS
    // =========================================================================

    public function test_rejects_invalid_youtube_url(): void
    {
        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'not-a-valid-url',
            'userName' => 'Test User',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['youtubeUrl']);
    }

    public function test_rejects_non_youtube_url(): void
    {
        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://vimeo.com/12345',
            'userName' => 'Test User',
        ]);

        // Should fail validation or return error
        $response->assertStatus(422);
    }

    public function test_requires_youtube_url(): void
    {
        $response = $this->postJson('/api/articles/from-youtube', [
            'userName' => 'Test User',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['youtubeUrl']);
    }

    // =========================================================================
    // CAPTIONS MODE TESTS
    // =========================================================================

    public function test_creates_article_from_youtube_captions(): void
    {
        // Mock the YouTube transcript service
        $this->mock(YouTubeTranscriptService::class, function ($mock) {
            $mock->shouldReceive('extractVideoId')
                ->andReturn('8iUjJlKBbp8');
            
            $mock->shouldReceive('getVideoMetadata')
                ->andReturn([
                    'title' => 'How To Play Devil Inside by INXS On Guitar',
                    'channel' => 'TrueFire',
                ]);
            
            $mock->shouldReceive('getTranscript')
                ->andReturn([
                    'success' => true,
                    'video_id' => '8iUjJlKBbp8',
                    'transcript' => 'This is a sample guitar lesson transcript about techniques, chords, and scales. ' .
                        'We will learn about pentatonic scales, blues licks, and rock riffs. ' .
                        str_repeat('More content about guitar playing. ', 20),
                ]);
        });

        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://www.youtube.com/watch?v=8iUjJlKBbp8',
            'userName' => 'Test User',
            'brandId' => 'truefire',
            'useWhisper' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'generating',
                'mode' => 'captions',
            ])
            ->assertJsonStructure([
                'id',
                'title',
                'status',
                'video_title',
                'mode',
                'message',
            ]);

        // Verify article was created
        $this->assertDatabaseHas('articles', [
            'source_type' => 'youtube',
            'source_url' => 'https://www.youtube.com/watch?v=8iUjJlKBbp8',
            'status' => 'generating',
            'brand_id' => 'truefire',
            'created_by' => 'Test User',
        ]);

        // Verify GenerateArticleJob was dispatched
        Queue::assertPushed(GenerateArticleJob::class, function ($job) {
            return $job->brandId === 'truefire';
        });
    }

    public function test_falls_back_to_whisper_when_no_captions(): void
    {
        // Mock service to return empty transcript
        $this->mock(YouTubeTranscriptService::class, function ($mock) {
            $mock->shouldReceive('extractVideoId')
                ->andReturn('test123');
            
            $mock->shouldReceive('getVideoMetadata')
                ->andReturn(['title' => 'Test Video']);
            
            $mock->shouldReceive('getTranscript')
                ->andThrow(new \Exception('No captions available'));
        });

        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://www.youtube.com/watch?v=test123',
            'useWhisper' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson(['mode' => 'whisper']);

        // Should fall back to Whisper job
        Queue::assertPushed(YouTubeTranscriptionJob::class);
    }

    // =========================================================================
    // WHISPER MODE TESTS
    // =========================================================================

    public function test_creates_article_with_whisper_mode(): void
    {
        $this->mock(YouTubeTranscriptService::class, function ($mock) {
            $mock->shouldReceive('extractVideoId')
                ->andReturn('whispertest123');
            
            $mock->shouldReceive('getVideoMetadata')
                ->andReturn([
                    'title' => 'Guitar Solo Tutorial',
                    'channel' => 'TrueFire',
                ]);
        });

        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://www.youtube.com/watch?v=whispertest123',
            'userName' => 'Pro User',
            'brandId' => 'truefire',
            'useWhisper' => true,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'generating',
                'mode' => 'whisper',
            ]);

        // Verify YouTubeTranscriptionJob was dispatched (not GenerateArticleJob)
        Queue::assertPushed(YouTubeTranscriptionJob::class, function ($job) {
            return $job->brandId === 'truefire';
        });

        Queue::assertNotPushed(GenerateArticleJob::class);
    }

    public function test_whisper_mode_is_default(): void
    {
        $this->mock(YouTubeTranscriptService::class, function ($mock) {
            $mock->shouldReceive('extractVideoId')->andReturn('default123');
            $mock->shouldReceive('getVideoMetadata')->andReturn(['title' => 'Test']);
        });

        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://www.youtube.com/watch?v=default123',
            // Note: useWhisper not specified, should default to true
        ]);

        $response->assertStatus(200)
            ->assertJson(['mode' => 'whisper']);
    }

    // =========================================================================
    // BRAND-SPECIFIC TESTS
    // =========================================================================

    public function test_uses_truefire_brand_settings(): void
    {
        $this->mockYouTubeService('tfvideo', 'Guitar Mastery Course');

        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://www.youtube.com/watch?v=tfvideo',
            'brandId' => 'truefire',
            'useWhisper' => false,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('articles', [
            'brand_id' => 'truefire',
        ]);
    }

    public function test_uses_artistworks_brand_settings(): void
    {
        $this->mockYouTubeService('awvideo', 'Violin Techniques');

        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://www.youtube.com/watch?v=awvideo',
            'brandId' => 'artistworks',
            'useWhisper' => false,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('articles', [
            'brand_id' => 'artistworks',
        ]);
    }

    public function test_defaults_to_truefire_brand(): void
    {
        $this->mockYouTubeService('nobrand', 'Some Video');

        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://www.youtube.com/watch?v=nobrand',
            // Note: brandId not specified
            'useWhisper' => false,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('articles', [
            'brand_id' => 'truefire',
        ]);
    }

    // =========================================================================
    // VIDEO ID EXTRACTION TESTS
    // =========================================================================

    public function test_extracts_video_id_from_standard_url(): void
    {
        $service = new YouTubeTranscriptService();
        
        $this->assertEquals(
            '8iUjJlKBbp8',
            $service->extractVideoId('https://www.youtube.com/watch?v=8iUjJlKBbp8')
        );
    }

    public function test_extracts_video_id_from_short_url(): void
    {
        $service = new YouTubeTranscriptService();
        
        $this->assertEquals(
            'dQw4w9WgXcQ',
            $service->extractVideoId('https://youtu.be/dQw4w9WgXcQ')
        );
    }

    public function test_extracts_video_id_from_embed_url(): void
    {
        $service = new YouTubeTranscriptService();
        
        $this->assertEquals(
            'abc123XYZ-_',
            $service->extractVideoId('https://www.youtube.com/embed/abc123XYZ-_')
        );
    }

    public function test_returns_null_for_invalid_url(): void
    {
        $service = new YouTubeTranscriptService();
        
        $this->assertNull($service->extractVideoId('https://vimeo.com/12345'));
        $this->assertNull($service->extractVideoId('not-a-url'));
    }

    // =========================================================================
    // ERROR HANDLING TESTS
    // =========================================================================

    public function test_handles_youtube_service_error(): void
    {
        $this->mock(YouTubeTranscriptService::class, function ($mock) {
            $mock->shouldReceive('extractVideoId')->andReturn(null);
            $mock->shouldReceive('getVideoMetadata')->andReturn(['title' => 'Test']);
        });

        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://www.youtube.com/watch?v=invalid',
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'Invalid YouTube URL']);
    }

    public function test_handles_transcript_extraction_failure_gracefully(): void
    {
        $this->mock(YouTubeTranscriptService::class, function ($mock) {
            $mock->shouldReceive('extractVideoId')->andReturn('failtest');
            $mock->shouldReceive('getVideoMetadata')->andReturn(['title' => 'Test Video']);
            $mock->shouldReceive('getTranscript')
                ->andThrow(new \Exception('Transcript service unavailable'));
        });

        $response = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://www.youtube.com/watch?v=failtest',
            'useWhisper' => false,
        ]);

        // Should fall back to Whisper mode
        $response->assertStatus(200)
            ->assertJson(['mode' => 'whisper']);
    }

    // =========================================================================
    // ARTICLE STATUS LIFECYCLE TESTS
    // =========================================================================

    public function test_article_transitions_to_draft_after_generation(): void
    {
        // Create an article in generating state
        $article = Article::factory()->create([
            'status' => 'generating',
            'source_type' => 'youtube',
            'source_url' => 'https://www.youtube.com/watch?v=lifecycle123',
            'transcript' => str_repeat('Sample transcript content. ', 50),
        ]);

        // Simulate successful generation
        $article->update([
            'title' => 'Generated Title',
            'content' => '<h2>Generated Content</h2><p>Article body here.</p>',
            'author' => 'AI Author',
            'meta_description' => 'Generated meta description',
            'slug' => 'generated-article-slug',
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'status' => 'draft',
        ]);
    }

    public function test_article_transitions_to_error_on_failure(): void
    {
        $article = Article::factory()->create([
            'status' => 'generating',
            'source_type' => 'youtube',
        ]);

        // Simulate failed generation
        $article->update([
            'status' => 'error',
            'error_message' => 'Bedrock API error: rate limit exceeded',
        ]);

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'status' => 'error',
            'error_message' => 'Bedrock API error: rate limit exceeded',
        ]);
    }

    // =========================================================================
    // INTEGRATION WITH EXISTING ARTICLE SYSTEM
    // =========================================================================

    public function test_youtube_article_appears_in_list(): void
    {
        $this->mockYouTubeService('listtest', 'My Guitar Video');

        $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://www.youtube.com/watch?v=listtest',
            'useWhisper' => false,
        ]);

        $response = $this->getJson('/api/articles');

        $response->assertStatus(200);
        
        $articles = $response->json('data') ?? $response->json();
        $this->assertNotEmpty($articles);
        
        $youtubeArticle = collect($articles)->firstWhere('source_type', 'youtube');
        $this->assertNotNull($youtubeArticle);
    }

    public function test_youtube_article_can_be_deleted(): void
    {
        $this->mockYouTubeService('deletetest', 'Delete Me');

        $createResponse = $this->postJson('/api/articles/from-youtube', [
            'youtubeUrl' => 'https://www.youtube.com/watch?v=deletetest',
            'useWhisper' => false,
        ]);

        $articleId = $createResponse->json('id');

        $deleteResponse = $this->deleteJson("/api/articles/{$articleId}");
        $deleteResponse->assertStatus(200);

        $this->assertSoftDeleted('articles', ['id' => $articleId]);
    }

    public function test_youtube_article_can_be_updated(): void
    {
        $article = Article::factory()->create([
            'source_type' => 'youtube',
            'status' => 'draft',
            'title' => 'Original Title',
        ]);

        $response = $this->putJson("/api/articles/{$article->id}", [
            'title' => 'Updated Title',
            'content' => '<p>Updated content</p>',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'title' => 'Updated Title',
        ]);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    protected function mockYouTubeService(string $videoId, string $title): void
    {
        $this->mock(YouTubeTranscriptService::class, function ($mock) use ($videoId, $title) {
            $mock->shouldReceive('extractVideoId')
                ->andReturn($videoId);
            
            $mock->shouldReceive('getVideoMetadata')
                ->andReturn([
                    'title' => $title,
                    'channel' => 'Test Channel',
                ]);
            
            $mock->shouldReceive('getTranscript')
                ->andReturn([
                    'success' => true,
                    'video_id' => $videoId,
                    'transcript' => 'This is a comprehensive guitar lesson about scales, chords, and techniques. ' .
                        str_repeat('More educational content about music and guitar playing. ', 20),
                ]);
        });
    }
}
