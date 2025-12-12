<?php

namespace Tests\Unit;

use App\Jobs\GenerateArticleJob;
use App\Models\Article;
use App\Services\ArticleGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GenerateArticleJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_job_updates_article_on_successful_generation(): void
    {
        $article = Article::factory()->generating()->create();

        // Mock the service
        $mockService = Mockery::mock(ArticleGeneratorService::class);
        $mockService->shouldReceive('generateFromTranscript')
            ->once()
            ->with('Test transcript', 'truefire')
            ->andReturn([
                'title' => 'Generated Title',
                'content' => '<p>Generated content</p>',
                'author' => 'AI Author',
                'meta_description' => 'SEO description',
                'slug' => 'generated-title',
            ]);

        $this->app->instance(ArticleGeneratorService::class, $mockService);

        $job = new GenerateArticleJob($article->id, 'Test transcript', 'truefire');
        $job->handle($mockService);

        $article->refresh();

        $this->assertEquals('Generated Title', $article->title);
        $this->assertEquals('<p>Generated content</p>', $article->content);
        $this->assertEquals('AI Author', $article->author);
        $this->assertEquals('draft', $article->status);
        $this->assertNull($article->error_message);
    }

    public function test_job_handles_generation_failure(): void
    {
        $article = Article::factory()->generating()->create();

        // Mock the service to throw an exception
        $mockService = Mockery::mock(ArticleGeneratorService::class);
        $mockService->shouldReceive('generateFromTranscript')
            ->once()
            ->andThrow(new \Exception('API Error'));

        $job = new GenerateArticleJob($article->id, 'Test transcript', 'truefire');
        $job->handle($mockService);

        $article->refresh();

        $this->assertEquals('error', $article->status);
        $this->assertEquals('API Error', $article->error_message);
    }

    public function test_job_skips_non_generating_articles(): void
    {
        $article = Article::factory()->published()->create();

        // Mock the service - should NOT be called
        $mockService = Mockery::mock(ArticleGeneratorService::class);
        $mockService->shouldNotReceive('generateFromTranscript');

        $job = new GenerateArticleJob($article->id, 'Test transcript', 'truefire');
        $job->handle($mockService);

        $article->refresh();

        // Status should remain unchanged
        $this->assertEquals('published', $article->status);
    }

    public function test_job_handles_missing_article(): void
    {
        // Mock the service - should NOT be called
        $mockService = Mockery::mock(ArticleGeneratorService::class);
        $mockService->shouldNotReceive('generateFromTranscript');

        $job = new GenerateArticleJob(99999, 'Test transcript', 'truefire');
        
        // Should not throw an exception - just returns early
        $job->handle($mockService);
        
        // Verify no articles were created or modified
        $this->assertDatabaseMissing('articles', ['id' => 99999]);
    }

    public function test_job_generates_unique_slug(): void
    {
        // Create existing article with same slug
        Article::factory()->create(['slug' => 'test-slug']);

        $article = Article::factory()->generating()->create();

        // Mock the service
        $mockService = Mockery::mock(ArticleGeneratorService::class);
        $mockService->shouldReceive('generateFromTranscript')
            ->once()
            ->andReturn([
                'title' => 'Test Slug',
                'content' => '<p>Content</p>',
                'author' => 'Author',
                'meta_description' => 'Description',
                'slug' => 'test-slug', // Same as existing
            ]);

        $job = new GenerateArticleJob($article->id, 'Test transcript', 'truefire');
        $job->handle($mockService);

        $article->refresh();

        // Slug should be unique (e.g., 'test-slug-1')
        $this->assertNotEquals('test-slug', $article->slug);
        $this->assertStringStartsWith('test-slug', $article->slug);
    }

    public function test_failed_method_updates_article_status(): void
    {
        $article = Article::factory()->generating()->create();

        $job = new GenerateArticleJob($article->id, 'Test transcript', 'truefire');
        $job->failed(new \Exception('Job failed completely'));

        $article->refresh();

        $this->assertEquals('error', $article->status);
        $this->assertStringContains('Job failed completely', $article->error_message);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            $message ?: "Failed asserting that '$haystack' contains '$needle'"
        );
    }
}
