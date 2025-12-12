<?php

namespace App\Jobs;

use App\Models\Article;
use App\Services\ArticleGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes
    public int $tries = 2;

    public int $articleId;
    public string $transcript;
    public string $brandId;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $articleId,
        string $transcript,
        string $brandId = 'truefire'
    ) {
        $this->articleId = $articleId;
        $this->transcript = $transcript;
        $this->brandId = $brandId;
    }

    /**
     * Execute the job.
     */
    public function handle(ArticleGeneratorService $generator): void
    {
        $article = Article::find($this->articleId);

        if (!$article) {
            Log::error('GenerateArticleJob: Article not found', ['article_id' => $this->articleId]);
            return;
        }

        if ($article->status !== 'generating') {
            Log::info('GenerateArticleJob: Article not in generating status, skipping', [
                'article_id' => $this->articleId,
                'status' => $article->status,
            ]);
            return;
        }

        Log::info('GenerateArticleJob: Starting article generation', [
            'article_id' => $this->articleId,
            'brand_id' => $this->brandId,
            'transcript_length' => strlen($this->transcript),
        ]);

        try {
            $result = $generator->generateFromTranscript($this->transcript, $this->brandId);

            // Generate a unique slug if not provided or already exists
            $slug = $result['slug'] ?? null;
            if ($slug) {
                $slug = Article::generateSlug($slug, $this->articleId);
            } else {
                $slug = Article::generateSlug($result['title'], $this->articleId);
            }

            $article->update([
                'title' => $result['title'],
                'content' => $result['content'],
                'author' => $result['author'],
                'meta_description' => $result['meta_description'],
                'slug' => $slug,
                'status' => 'draft',
                'error_message' => null,
            ]);

            Log::info('GenerateArticleJob: Article generated successfully', [
                'article_id' => $this->articleId,
                'title' => $result['title'],
            ]);

        } catch (\Exception $e) {
            Log::error('GenerateArticleJob: Failed to generate article', [
                'article_id' => $this->articleId,
                'error' => $e->getMessage(),
            ]);

            $article->update([
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateArticleJob: Job failed', [
            'article_id' => $this->articleId,
            'exception' => $exception->getMessage(),
        ]);

        $article = Article::find($this->articleId);
        if ($article) {
            $article->update([
                'status' => 'error',
                'error_message' => 'Article generation failed: ' . $exception->getMessage(),
            ]);
        }
    }
}


