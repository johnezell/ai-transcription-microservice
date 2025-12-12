<?php

namespace Tests\Feature;

use App\Jobs\GenerateArticleJob;
use App\Models\Article;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticleApiTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // LIST ARTICLES
    // =========================================================================

    public function test_can_list_articles(): void
    {
        Article::factory()->count(5)->create();

        $response = $this->getJson('/api/articles');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'title', 'status', 'brand_id', 'created_at'],
                ],
                'pagination' => ['page', 'limit', 'total', 'totalPages'],
            ]);

        $this->assertCount(5, $response->json('data'));
    }

    public function test_can_filter_articles_by_brand(): void
    {
        Article::factory()->count(3)->forBrand('truefire')->create();
        Article::factory()->count(2)->forBrand('artistworks')->create();

        $response = $this->getJson('/api/articles?brandId=truefire');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_articles_list_is_paginated(): void
    {
        Article::factory()->count(20)->create();

        $response = $this->getJson('/api/articles?page=2&limit=5');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data'));
        $this->assertEquals(2, $response->json('pagination.page'));
        $this->assertEquals(4, $response->json('pagination.totalPages'));
    }

    // =========================================================================
    // GET SINGLE ARTICLE
    // =========================================================================

    public function test_can_get_single_article(): void
    {
        $article = Article::factory()->create([
            'title' => 'Test Article Title',
            'content' => '<p>Test content</p>',
        ]);

        $response = $this->getJson("/api/articles/{$article->id}");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $article->id,
                'title' => 'Test Article Title',
            ]);
    }

    public function test_returns_404_for_nonexistent_article(): void
    {
        $response = $this->getJson('/api/articles/99999');

        $response->assertStatus(404)
            ->assertJson(['error' => 'Article not found']);
    }

    // =========================================================================
    // CREATE FROM TRANSCRIPT
    // =========================================================================

    public function test_can_create_article_from_transcript(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/articles/from-transcript', [
            'transcript' => str_repeat('This is a test transcript about guitar lessons. ', 10),
            'userName' => 'Test User',
            'brandId' => 'truefire',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['id', 'title', 'status'])
            ->assertJson(['status' => 'generating']);

        Queue::assertPushed(GenerateArticleJob::class);
        $this->assertDatabaseHas('articles', [
            'status' => 'generating',
            'brand_id' => 'truefire',
            'created_by' => 'Test User',
        ]);
    }

    public function test_create_from_transcript_requires_minimum_length(): void
    {
        $response = $this->postJson('/api/articles/from-transcript', [
            'transcript' => 'Too short',
        ]);

        $response->assertStatus(422);
    }

    public function test_create_from_transcript_uses_defaults(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/articles/from-transcript', [
            'transcript' => str_repeat('This is a test transcript about music production techniques. ', 10),
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('articles', [
            'brand_id' => 'truefire',
            'created_by' => 'Anonymous',
        ]);
    }

    // =========================================================================
    // CREATE FROM VIDEO
    // =========================================================================

    public function test_can_create_article_from_video(): void
    {
        Queue::fake();

        $video = Video::factory()->transcribed()->create();

        $response = $this->postJson('/api/articles/from-video', [
            'videoId' => $video->id,
            'userName' => 'Video User',
            'brandId' => 'artistworks',
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'generating']);

        Queue::assertPushed(GenerateArticleJob::class);
    }

    public function test_create_from_video_returns_error_for_missing_video(): void
    {
        $response = $this->postJson('/api/articles/from-video', [
            'videoId' => 'non-existent-uuid-12345',
        ]);

        $response->assertStatus(404)
            ->assertJson(['error' => 'Video not found']);
    }

    public function test_create_from_video_returns_error_without_transcript(): void
    {
        $video = Video::factory()->create(['transcript_text' => null]);

        $response = $this->postJson('/api/articles/from-video', [
            'videoId' => $video->id,
        ]);

        $response->assertStatus(400)
            ->assertJson(['error' => 'Video does not have a transcript']);
    }

    // =========================================================================
    // UPDATE ARTICLE
    // =========================================================================

    public function test_can_update_article(): void
    {
        $article = Article::factory()->create();

        $response = $this->putJson("/api/articles/{$article->id}", [
            'title' => 'Updated Title',
            'status' => 'published',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Article updated successfully']);

        $this->assertDatabaseHas('articles', [
            'id' => $article->id,
            'title' => 'Updated Title',
            'status' => 'published',
        ]);
    }

    public function test_update_generates_unique_slug(): void
    {
        Article::factory()->create(['slug' => 'existing-slug']);
        $article = Article::factory()->create(['slug' => 'original-slug']);

        $response = $this->putJson("/api/articles/{$article->id}", [
            'slug' => 'existing-slug',
        ]);

        $response->assertStatus(200);

        $article->refresh();
        // Should generate a unique slug variation
        $this->assertNotEquals('existing-slug', $article->slug);
    }

    public function test_update_returns_404_for_nonexistent_article(): void
    {
        $response = $this->putJson('/api/articles/99999', [
            'title' => 'Updated',
        ]);

        $response->assertStatus(404);
    }

    // =========================================================================
    // DELETE ARTICLE
    // =========================================================================

    public function test_can_delete_article(): void
    {
        $article = Article::factory()->create();

        $response = $this->deleteJson("/api/articles/{$article->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Article deleted successfully']);

        // Soft deleted, so still in database but not accessible
        $this->assertSoftDeleted('articles', ['id' => $article->id]);
    }

    public function test_delete_returns_404_for_nonexistent_article(): void
    {
        $response = $this->deleteJson('/api/articles/99999');

        $response->assertStatus(404);
    }

    // =========================================================================
    // BRAND SETTINGS
    // =========================================================================

    public function test_can_get_brand_settings(): void
    {
        $response = $this->getJson('/api/article-settings?brandId=truefire');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'brandId',
                'settings',
                'availableBrands',
            ]);
    }

    public function test_can_update_brand_settings(): void
    {
        $response = $this->putJson('/api/article-settings', [
            'brandId' => 'truefire',
            'system_prompt' => 'Custom prompt for testing',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Settings updated successfully']);

        $this->assertDatabaseHas('brand_settings', [
            'brand_id' => 'truefire',
            'key' => 'system_prompt',
            'value' => 'Custom prompt for testing',
        ]);
    }

    // =========================================================================
    // COMMENTS
    // =========================================================================

    public function test_can_get_article_comments(): void
    {
        $article = Article::factory()->create();

        $response = $this->getJson("/api/articles/{$article->id}/comments");

        $response->assertStatus(200)
            ->assertJsonIsArray();
    }

    public function test_can_add_comment_to_article(): void
    {
        $article = Article::factory()->create();

        $response = $this->postJson("/api/articles/{$article->id}/comments", [
            'user_name' => 'Commenter',
            'content' => 'This is a test comment.',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'user_name', 'content']);

        $this->assertDatabaseHas('article_comments', [
            'article_id' => $article->id,
            'user_name' => 'Commenter',
            'content' => 'This is a test comment.',
        ]);
    }

    public function test_add_comment_validates_required_fields(): void
    {
        $article = Article::factory()->create();

        $response = $this->postJson("/api/articles/{$article->id}/comments", []);

        $response->assertStatus(422);
    }

    public function test_can_delete_comment(): void
    {
        $article = Article::factory()->create();
        
        // Create comment directly
        $comment = $article->allComments()->create([
            'user_name' => 'Test User',
            'content' => 'Test comment to delete',
        ]);

        $response = $this->deleteJson("/api/articles/{$article->id}/comments/{$comment->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Comment deleted successfully']);

        // ArticleComment uses SoftDeletes
        $this->assertSoftDeleted('article_comments', ['id' => $comment->id]);
    }
}
