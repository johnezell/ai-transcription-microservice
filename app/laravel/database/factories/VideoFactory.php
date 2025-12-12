<?php

namespace Database\Factories;

use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Video>
 */
class VideoFactory extends Factory
{
    protected $model = Video::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'original_filename' => fake()->word() . '.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => fake()->numberBetween(1000000, 100000000),
            'status' => 'uploaded',
            'storage_path' => null,
            'audio_path' => null,
            'transcript_path' => null,
            'transcript_text' => null,
        ];
    }

    /**
     * Indicate that the video has been transcribed.
     */
    public function transcribed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'transcribed',
            'transcript_text' => fake()->paragraphs(10, true),
        ]);
    }

    /**
     * Indicate that the video processing is complete.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'transcript_text' => fake()->paragraphs(10, true),
        ]);
    }

    /**
     * Indicate that the video has an error.
     */
    public function withError(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }
}
