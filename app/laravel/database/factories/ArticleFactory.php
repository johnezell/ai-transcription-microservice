<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(6);
        
        return [
            'title' => $title,
            'content' => '<h2>Introduction</h2><p>' . fake()->paragraphs(3, true) . '</p>',
            'author' => fake()->name(),
            'meta_description' => fake()->text(150),
            'slug' => Str::slug($title),
            'source_type' => 'transcript',
            'source_url' => null,
            'source_file' => null,
            'transcript' => fake()->paragraphs(5, true),
            'video_id' => null,
            'status' => 'draft',
            'error_message' => null,
            'brand_id' => fake()->randomElement(['truefire', 'artistworks', 'blayze', 'faderpro']),
            'created_by' => fake()->name(),
        ];
    }

    /**
     * Indicate that the article is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
        ]);
    }

    /**
     * Indicate that the article is generating.
     */
    public function generating(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'generating',
            'title' => 'Generating article...',
            'content' => '<p>Your article is being generated...</p>',
        ]);
    }

    /**
     * Indicate that the article has an error.
     */
    public function withError(string $message = 'Generation failed'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'error',
            'error_message' => $message,
        ]);
    }

    /**
     * Indicate the article belongs to a specific brand.
     */
    public function forBrand(string $brandId): static
    {
        return $this->state(fn (array $attributes) => [
            'brand_id' => $brandId,
        ]);
    }

    /**
     * Associate with a video.
     */
    public function forVideo(Video $video): static
    {
        return $this->state(fn (array $attributes) => [
            'video_id' => $video->id,
            'transcript' => $video->transcript_text ?? fake()->paragraphs(5, true),
        ]);
    }
}
