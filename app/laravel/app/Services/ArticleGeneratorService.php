<?php

namespace App\Services;

use App\Models\Article;
use App\Models\BrandSetting;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ArticleGeneratorService
{
    protected ?BedrockRuntimeClient $bedrockClient = null;
    protected string $defaultModel = 'us.anthropic.claude-haiku-4-5-20251001-v1:0';
    protected ?string $bearerToken = null;
    protected string $region = 'us-east-1';

    public function __construct(?BedrockRuntimeClient $bedrockClient = null)
    {
        $this->region = config('services.aws.region', 'us-east-1');
        $this->bearerToken = env('AWS_BEARER_TOKEN_BEDROCK');
        
        // Only create SDK client if no bearer token is set
        if (!$this->bearerToken) {
            $this->bedrockClient = $bedrockClient ?? new BedrockRuntimeClient([
                'version' => 'latest',
                'region' => $this->region,
            ]);
        }
    }

    /**
     * Set the Bedrock client (useful for testing)
     */
    public function setBedrockClient(BedrockRuntimeClient $client): self
    {
        $this->bedrockClient = $client;
        $this->bearerToken = null; // Prefer SDK client when explicitly set
        return $this;
    }
    
    /**
     * Set the bearer token for API key authentication
     */
    public function setBearerToken(string $token): self
    {
        $this->bearerToken = $token;
        return $this;
    }

    /**
     * Generate an article from a transcript
     */
    public function generateFromTranscript(string $transcript, string $brandId = 'truefire'): array
    {
        $systemPrompt = BrandSetting::getSystemPrompt($brandId);
        $model = BrandSetting::getLlmModel($brandId);

        // Get existing slugs to avoid duplicates
        $existingSlugs = Article::whereNotNull('slug')->pluck('slug')->toArray();

        // Get recent published articles for internal linking
        $recentArticles = Article::forBrand($brandId)
            ->published()
            ->whereNotNull('slug')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get(['title', 'slug', 'meta_description']);

        // Build internal linking context
        $internalLinkingContext = $this->buildInternalLinkingContext($recentArticles);

        $userPrompt = $this->buildUserPrompt($transcript, $existingSlugs, $internalLinkingContext);

        try {
            $response = $this->invokeModel($model, $systemPrompt, $userPrompt);
            return $this->parseResponse($response);
        } catch (\Exception $e) {
            Log::error('Article generation failed', [
                'error' => $e->getMessage(),
                'brand_id' => $brandId,
            ]);
            throw $e;
        }
    }

    /**
     * Invoke the Bedrock model
     */
    protected function invokeModel(string $modelId, string $systemPrompt, string $userPrompt): string
    {
        $body = [
            'anthropic_version' => 'bedrock-2023-05-31',
            'max_tokens' => 8192,
            'system' => $systemPrompt,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
        ];

        Log::info('Invoking Bedrock model', [
            'model_id' => $modelId,
            'prompt_length' => strlen($userPrompt),
            'auth_method' => $this->bearerToken ? 'bearer_token' : 'sdk',
        ]);

        // Use bearer token authentication if available
        if ($this->bearerToken) {
            return $this->invokeWithBearerToken($modelId, $body);
        }

        // Fall back to SDK authentication
        $result = $this->bedrockClient->invokeModel([
            'modelId' => $modelId,
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode($body),
        ]);

        $responseBody = json_decode($result['body'], true);

        if (isset($responseBody['content'][0]['text'])) {
            return $responseBody['content'][0]['text'];
        }

        throw new \Exception('Invalid response from Bedrock: ' . json_encode($responseBody));
    }
    
    /**
     * Invoke model using bearer token (Bedrock API Key)
     */
    protected function invokeWithBearerToken(string $modelId, array $body): string
    {
        $url = "https://bedrock-runtime.{$this->region}.amazonaws.com/model/" . urlencode($modelId) . "/invoke";
        
        Log::info('Invoking Bedrock with bearer token', [
            'url' => $url,
            'model_id' => $modelId,
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->bearerToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(120)->post($url, $body);

        if (!$response->successful()) {
            $error = $response->json('message') ?? $response->body();
            Log::error('Bedrock API error', [
                'status' => $response->status(),
                'error' => $error,
            ]);
            throw new \Exception("Bedrock API error ({$response->status()}): {$error}");
        }

        $responseBody = $response->json();

        if (isset($responseBody['content'][0]['text'])) {
            return $responseBody['content'][0]['text'];
        }

        throw new \Exception('Invalid response from Bedrock: ' . json_encode($responseBody));
    }

    /**
     * Parse the model response into article components
     */
    public function parseResponse(string $responseText): array
    {
        // Try to parse as JSON
        try {
            // Remove markdown code blocks if present
            if (preg_match('/```json\n?([\s\S]*?)\n?```/', $responseText, $matches)) {
                $jsonText = $matches[1];
            } elseif (preg_match('/```\n?([\s\S]*?)\n?```/', $responseText, $matches)) {
                $jsonText = $matches[1];
            } else {
                $jsonText = $responseText;
            }

            $article = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);

            return [
                'title' => $article['title'] ?? 'Generated Article',
                'content' => $article['content'] ?? '',
                'author' => $article['author'] ?? null,
                'meta_description' => $article['metaDescription'] ?? null,
                'slug' => $article['slug'] ?? null,
            ];
        } catch (\JsonException $e) {
            Log::warning('JSON parsing failed, attempting manual extraction', [
                'preview' => substr($responseText, 0, 500),
            ]);

            // Manual extraction fallback
            return $this->manualExtract($responseText);
        }
    }

    /**
     * Manually extract article fields from response text
     */
    public function manualExtract(string $responseText): array
    {
        $title = null;
        $author = null;
        $metaDescription = null;
        $slug = null;
        $content = $responseText;

        if (preg_match('/"title":\s*"([^"]+)"/', $responseText, $m)) {
            $title = $m[1];
        }
        if (preg_match('/"author":\s*"([^"]+)"/', $responseText, $m)) {
            $author = $m[1];
        }
        if (preg_match('/"metaDescription":\s*"([^"]+)"/', $responseText, $m)) {
            $metaDescription = $m[1];
        }
        if (preg_match('/"slug":\s*"([^"]+)"/', $responseText, $m)) {
            $slug = $m[1];
        }
        if (preg_match('/"content":\s*"([\s\S]+)"\s*}/', $responseText, $m)) {
            $content = $m[1];
            // Unescape JSON string
            $content = stripcslashes($content);
        }

        return [
            'title' => $title ?? 'Generated Article',
            'content' => $content,
            'author' => $author,
            'meta_description' => $metaDescription,
            'slug' => $slug,
        ];
    }

    /**
     * Build the user prompt for article generation
     */
    public function buildUserPrompt(string $transcript, array $existingSlugs = [], string $internalLinkingContext = ''): string
    {
        $existingSlugsStr = implode(', ', $existingSlugs);

        return <<<PROMPT
Transform the following video transcript into a comprehensive, well-structured blog article.

The article should:
- Have a compelling, SEO-friendly title
- Include an engaging introduction
- Be organized with clear sections and subheadings
- Maintain technical accuracy while being accessible
- Include practical applications and examples
- Have a strong conclusion
- Be formatted in clean HTML (use <h2>, <h3>, <p>, <ul>, <ol>, <strong>, <em> tags)

Additional requirements:
- Create a suggested author name (e.g., "Sarah Johnson" or "TrueFire Team")
- Write a compelling meta description (150-160 characters) for SEO
- Generate a unique URL slug (lowercase, hyphens, no special characters)

IMPORTANT: The slug must NOT match any of these existing slugs: {$existingSlugsStr}
{$internalLinkingContext}

Transcript:
{$transcript}

CRITICAL: You MUST respond with ONLY a valid JSON object. Do not include any text before or after the JSON. Use this exact format:

{
  "title": "Article Title Here",
  "author": "Author Name",
  "metaDescription": "Compelling 150-160 character description for SEO",
  "slug": "url-friendly-slug-here",
  "content": "<h2>Introduction</h2><p>Article content here with proper HTML formatting and internal links where relevant...</p>"
}

Remember: Return ONLY the JSON object, nothing else.
PROMPT;
    }

    /**
     * Build internal linking context for the prompt
     */
    public function buildInternalLinkingContext($recentArticles): string
    {
        if ($recentArticles->isEmpty()) {
            return '';
        }

        $articleList = $recentArticles->map(function ($article, $index) {
            $desc = $article->meta_description ?? 'No description available';
            return ($index + 1) . ". Title: \"{$article->title}\"\n   Slug: {$article->slug}\n   Description: {$desc}";
        })->implode("\n");

        $exampleSlug = $recentArticles->first()->slug ?? 'example-slug';

        return <<<CONTEXT


## Internal Linking

We have {$recentArticles->count()} existing articles on our blog that you can reference. When writing the new article, please add contextual internal links where relevant. Look for natural opportunities to link to related content.

**Available articles to link to:**
{$articleList}

**Internal Linking Guidelines:**
- Only link when it's genuinely relevant and adds value to the reader
- Use natural anchor text (not "click here" or generic phrases)
- Link 2-5 related articles maximum (don't overdo it)
- Format links as: <a href="/articles/{$exampleSlug}">natural anchor text</a>
- Place links naturally within the content, not forced

**Example of good internal linking:**
"If you're struggling with fingerpicking patterns, check out our guide on <a href="/articles/fingerpicking-fundamentals">mastering fingerpicking fundamentals</a> before diving into these advanced techniques."
CONTEXT;
    }
}


