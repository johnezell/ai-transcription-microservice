<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Services\ArticleGeneratorService;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class ArticleGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ArticleGeneratorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create service with a mock client (we won't use it for pure parsing tests)
        $mockClient = Mockery::mock(BedrockRuntimeClient::class);
        $this->service = new ArticleGeneratorService($mockClient);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // JSON PARSING TESTS
    // =========================================================================

    public function test_parse_response_handles_valid_json(): void
    {
        $jsonResponse = json_encode([
            'title' => 'Learn Guitar Scales',
            'author' => 'John Smith',
            'metaDescription' => 'Master the essential guitar scales for improvisation',
            'slug' => 'learn-guitar-scales',
            'content' => '<h2>Introduction</h2><p>Scales are fundamental...</p>',
        ]);

        $result = $this->service->parseResponse($jsonResponse);

        $this->assertEquals('Learn Guitar Scales', $result['title']);
        $this->assertEquals('John Smith', $result['author']);
        $this->assertEquals('Master the essential guitar scales for improvisation', $result['meta_description']);
        $this->assertEquals('learn-guitar-scales', $result['slug']);
        $this->assertStringContains('Scales are fundamental', $result['content']);
    }

    public function test_parse_response_handles_json_with_markdown_code_blocks(): void
    {
        $responseWithCodeBlock = <<<RESPONSE
```json
{
    "title": "Blues Guitar Techniques",
    "author": "Sarah Johnson",
    "metaDescription": "Essential blues techniques for guitarists",
    "slug": "blues-guitar-techniques",
    "content": "<h2>The Blues</h2><p>Blues is the foundation of rock...</p>"
}
```
RESPONSE;

        $result = $this->service->parseResponse($responseWithCodeBlock);

        $this->assertEquals('Blues Guitar Techniques', $result['title']);
        $this->assertEquals('Sarah Johnson', $result['author']);
        $this->assertEquals('blues-guitar-techniques', $result['slug']);
    }

    public function test_parse_response_handles_plain_code_blocks(): void
    {
        $responseWithCodeBlock = <<<RESPONSE
```
{
    "title": "Fingerpicking Fundamentals",
    "author": "Mike Wilson",
    "metaDescription": "Learn fingerpicking patterns step by step",
    "slug": "fingerpicking-fundamentals",
    "content": "<p>Fingerpicking is a beautiful technique...</p>"
}
```
RESPONSE;

        $result = $this->service->parseResponse($responseWithCodeBlock);

        $this->assertEquals('Fingerpicking Fundamentals', $result['title']);
        $this->assertEquals('Mike Wilson', $result['author']);
    }

    public function test_parse_response_falls_back_to_manual_extraction(): void
    {
        // Invalid JSON but contains extractable fields
        $malformedResponse = <<<RESPONSE
Here's the article:
"title": "Chord Progressions 101"
"author": "TrueFire Team"
"metaDescription": "Understanding common chord progressions"
"slug": "chord-progressions-101"
"content": "<p>Chord progressions are sequences of chords...</p>"
}
RESPONSE;

        $result = $this->service->parseResponse($malformedResponse);

        $this->assertEquals('Chord Progressions 101', $result['title']);
        $this->assertEquals('TrueFire Team', $result['author']);
    }

    public function test_parse_response_provides_default_title(): void
    {
        $responseWithoutTitle = 'Some random text without valid JSON';

        $result = $this->service->parseResponse($responseWithoutTitle);

        $this->assertEquals('Generated Article', $result['title']);
    }

    // =========================================================================
    // MANUAL EXTRACTION TESTS
    // =========================================================================

    public function test_manual_extract_extracts_all_fields(): void
    {
        $text = <<<TEXT
{
    "title": "Guitar Maintenance Guide",
    "author": "Chris Brown",
    "metaDescription": "Keep your guitar in top condition",
    "slug": "guitar-maintenance-guide",
    "content": "<h2>Caring for Your Guitar</h2><p>Regular maintenance is key...</p>"
}
TEXT;

        $result = $this->service->manualExtract($text);

        $this->assertEquals('Guitar Maintenance Guide', $result['title']);
        $this->assertEquals('Chris Brown', $result['author']);
        $this->assertEquals('Keep your guitar in top condition', $result['meta_description']);
        $this->assertEquals('guitar-maintenance-guide', $result['slug']);
    }

    public function test_manual_extract_handles_partial_data(): void
    {
        $text = '"title": "Partial Article"';

        $result = $this->service->manualExtract($text);

        $this->assertEquals('Partial Article', $result['title']);
        $this->assertNull($result['author']);
        $this->assertNull($result['slug']);
    }

    // =========================================================================
    // PROMPT BUILDING TESTS
    // =========================================================================

    public function test_build_user_prompt_includes_transcript(): void
    {
        $transcript = 'This is a sample transcript about guitar techniques.';

        $prompt = $this->service->buildUserPrompt($transcript);

        $this->assertStringContains($transcript, $prompt);
        $this->assertStringContains('Transform the following video transcript', $prompt);
        $this->assertStringContains('SEO-friendly title', $prompt);
    }

    public function test_build_user_prompt_includes_existing_slugs(): void
    {
        $transcript = 'Sample transcript text.';
        $existingSlugs = ['guitar-basics', 'advanced-scales', 'blues-licks'];

        $prompt = $this->service->buildUserPrompt($transcript, $existingSlugs);

        $this->assertStringContains('guitar-basics', $prompt);
        $this->assertStringContains('advanced-scales', $prompt);
        $this->assertStringContains('must NOT match', $prompt);
    }

    public function test_build_user_prompt_includes_internal_linking_context(): void
    {
        $transcript = 'Sample transcript.';
        $linkingContext = 'Available articles to link to: Article 1, Article 2';

        $prompt = $this->service->buildUserPrompt($transcript, [], $linkingContext);

        $this->assertStringContains('Available articles to link to', $prompt);
    }

    public function test_build_user_prompt_requires_json_response(): void
    {
        $transcript = 'Sample transcript.';

        $prompt = $this->service->buildUserPrompt($transcript);

        $this->assertStringContains('CRITICAL: You MUST respond with ONLY a valid JSON object', $prompt);
        $this->assertStringContains('"title":', $prompt);
        $this->assertStringContains('"content":', $prompt);
    }

    // =========================================================================
    // INTERNAL LINKING CONTEXT TESTS
    // =========================================================================

    public function test_build_internal_linking_context_with_empty_collection(): void
    {
        $emptyCollection = collect([]);

        $result = $this->service->buildInternalLinkingContext($emptyCollection);

        $this->assertEquals('', $result);
    }

    public function test_build_internal_linking_context_with_articles(): void
    {
        $articles = collect([
            (object) ['title' => 'Guitar Basics', 'slug' => 'guitar-basics', 'meta_description' => 'Learn the basics'],
            (object) ['title' => 'Advanced Scales', 'slug' => 'advanced-scales', 'meta_description' => 'Master scales'],
        ]);

        $result = $this->service->buildInternalLinkingContext($articles);

        $this->assertStringContains('Guitar Basics', $result);
        $this->assertStringContains('guitar-basics', $result);
        $this->assertStringContains('Advanced Scales', $result);
        $this->assertStringContains('2 existing articles', $result);
        $this->assertStringContains('Internal Linking Guidelines', $result);
    }

    public function test_build_internal_linking_context_handles_null_description(): void
    {
        $articles = collect([
            (object) ['title' => 'No Description Article', 'slug' => 'no-desc', 'meta_description' => null],
        ]);

        $result = $this->service->buildInternalLinkingContext($articles);

        $this->assertStringContains('No description available', $result);
    }

    // =========================================================================
    // INTEGRATION TEST WITH MOCKED BEDROCK
    // =========================================================================

    public function test_generate_from_transcript_with_mocked_bedrock(): void
    {
        // Create a mock response
        $mockResponse = json_encode([
            'title' => 'Mastering the Pentatonic Scale',
            'author' => 'Guitar Pro',
            'metaDescription' => 'Learn the pentatonic scale patterns',
            'slug' => 'mastering-pentatonic-scale',
            'content' => '<h2>The Pentatonic Scale</h2><p>The pentatonic scale is...</p>',
        ]);

        $bedrockResponse = [
            'content' => [
                ['text' => $mockResponse],
            ],
        ];

        // Create a mock Bedrock client
        $mockClient = Mockery::mock(BedrockRuntimeClient::class);
        $mockClient->shouldReceive('invokeModel')
            ->once()
            ->andReturn(new Result(['body' => json_encode($bedrockResponse)]));

        $service = new ArticleGeneratorService($mockClient);

        $result = $service->generateFromTranscript('Sample transcript about guitar scales.', 'truefire');

        $this->assertEquals('Mastering the Pentatonic Scale', $result['title']);
        $this->assertEquals('Guitar Pro', $result['author']);
        $this->assertEquals('mastering-pentatonic-scale', $result['slug']);
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
