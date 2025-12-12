<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class BrandSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'key',
        'value',
    ];

    /**
     * Available brands configuration
     */
    public const BRANDS = [
        'truefire' => [
            'id' => 'truefire',
            'name' => 'TrueFire',
            'website' => 'truefire.com',
            'logo' => 'https://df4emreqpcien.cloudfront.net/images/headless-page/new-tf-logo-black-600.png?width=200&format=webp',
            'primaryColor' => '#E74C3C',
            'description' => 'Guitar Lessons from the Pros',
        ],
        'artistworks' => [
            'id' => 'artistworks',
            'name' => 'ArtistWorks',
            'website' => 'artistworks.com',
            'logo' => 'data:image/svg+xml;base64,PHN2ZyBpZD0iTGF5ZXJfMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB2ZXJzaW9uPSIxLjEiIHZpZXdCb3g9IjAgMCA0NzMuNCAxMjkuMiI+PHN0eWxlPi5zdDB7ZmlsbDojMDAwfTwvc3R5bGU+PHBhdGggY2xhc3M9InN0MCIgZD0iTTEzMi4yIDQyLjdsLTE1LjEgNDMuN2g5LjlsMy4yLTEwSDE0Nmw3LjIgMTBoOS45bC0xNS4xLTQzLjdoLTExLjlaTTEzMi42IDY5LjJsNS40LTE2LjVoLjNsNS40IDE2LjVoLTExLjFaIi8+PHBhdGggY2xhc3M9InN0MCIgZD0iTTE3OC45IDUzLjFjLTEuOCAwLTMuNS41LTQuOSAxLjYtMS40IDEtMi40IDIuNi0zIDQuNmgtLjN2LTUuN2gtOC44djMyLjhoOS4xdi0xOC42YzAtMS4zLjMtMi41LjktMy41LjYtMSAxLjQtMS44IDIuNS0yLjQgMS0uNiAyLjItLjkgMy42LS45czEuMyAwIDIuMS4xYy44IDAgMS4zLjIgMS44LjN2LTguMWMtLjQgMC0uOS0uMi0xLjQtLjItLjUgMC0xIDAtMS41IDBaIi8+PHBhdGggY2xhc3M9InN0MCIgZD0iTTIwMS40IDc5LjRjLS40IDAtLjkuMS0xLjMuMS0uNiAwLTEuMSAwLTEuNi0uMy0uNS0uMi0uOC0uNS0xLjEtMS0uMy0uNS0uNC0xLjEtLjQtMnYtMTUuOWg2LjJ2LTYuOGgtNi4ydi03LjloLTkuMXY3LjloLTQuNXY2LjhoNC41djE3LjFjMCAyLjEuNCAzLjkgMS4zIDUuMy45IDEuNCAyLjIgMi40IDMuOCAzLjEgMS42LjcgMy42IDEgNS44LjkgMS4yIDAgMi4yLS4yIDMtLjQuOC0uMiAxLjUtLjQgMS45LS41bC0xLjQtNi44Yy0uMiAwLS42LjEtMSAuMloiLz48cGF0aCBjbGFzcz0ic3QwIiBkPSJNMjExLjggNDAuMmMtMS4zIDAtMi41LjUtMy41IDEuNC0xIC45LTEuNSAyLTEuNSAzLjJzLjUgMi40IDEuNSAzLjNjMSAuOSAyLjEgMS40IDMuNSAxLjRzMi41LS41IDMuNS0xLjRjMS0uOSAxLjQtMiAxLjQtMy4zcy0uNS0yLjMtMS40LTMuMmMtMS0uOS0yLjEtMS40LTMuNS0xLjRaIi8+PHJlY3QgY2xhc3M9InN0MCIgeD0iMjA3LjMiIHk9IjUzLjYiIHdpZHRoPSI5LjEiIGhlaWdodD0iMzIuOCIvPjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik0yMzkuOCA2Ny4zbC01LjktMS4yYy0xLjUtLjMtMi42LS44LTMuMi0xLjMtLjYtLjUtLjktMS4yLS45LTJzLjUtMS44IDEuNS0yLjRjMS0uNiAyLjItLjkgMy42LS45czIgLjIgMi43LjVjLjguMyAxLjQuOCAxLjggMS40LjUuNi44IDEuMi45IDEuOWw4LjMtLjVjLS40LTMtMS44LTUuNC00LjItNy4yLTIuNC0xLjgtNS42LTIuNi05LjgtMi42cy01LjIuNC03LjMgMS4yYy0yLjEuOC0zLjcgMi00LjggMy41LTEuMSAxLjUtMS43IDMuMy0xLjcgNS40cy44IDQuNSAyLjMgNmMxLjYgMS42IDMuOSAyLjcgNy4xIDMuM2w1LjcgMS4xYzEuNC4zIDIuNS43IDMuMiAxLjIuNy41IDEgMS4yIDEgMiAwIDEtLjUgMS44LTEuNSAyLjQtMSAuNi0yLjMuOS0zLjkuOXMtMy0uMy00LTFjLTEtLjctMS43LTEuNy0yLTNsLTguOS41YzQuNCAzLjEgMS45IDUuNiA0LjUgNy40IDIuNiAxLjggNiAyLjcgMTAuNCAyLjdzNS40LS41IDcuNS0xLjRjMi4yLS45IDMuOS0yLjIgNS4yLTMuOCAxLjMtMS42IDEuOS0zLjUgMS45LTUuN3MtLjgtNC4zLTIuMy01LjdjLTEuNi0xLjUtMy45LTIuNS03LjEtMy4yWiIvPjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik0yNjguMiA3OS40Yy0uNCAwLS45LjEtMS4zLjEtLjYgMC0xLjEgMC0xLjYtLjMtLjUtLjItLjgtLjUtMS4xLTEtLjMtLjUtLjQtMS4xLS40LTJ2LTE1LjloNi4ydi02LjhoLTYuMnYtNy45aC05LjF2Ny45aC00LjV2Ni44aDQuNXYxNy4xYzAgMi4xLjQgMy45IDEuMyA1LjMuOSAxLjQgMi4yIDIuNCAzLjggMy4xIDEuNi43IDMuNiAxIDUuOC45IDEuMiAwIDIuMi0uMiAzLS40LjgtLjIgMS41LS40IDEuOS0uNWwtMS40LTYuOGMtLjIgMC0uNi4xLTEgLjJaIi8+PHBvbHlnb24gY2xhc3M9InN0MCIgcG9pbnRzPSIzMjAuNSA0Mi43IDMxMy4zIDczLjEgMzEyLjkgNzMuMSAzMDUgNDIuNyAyOTYuMyA0Mi43IDI4OC4zIDczIDI4OCA3MyAyODAuNyA0Mi43IDI3MC42IDQyLjcgMjgzLjEgODYuNCAyOTIuMiA4Ni40IDMwMC41IDU3LjggMzAwLjggNTcuOCAzMDkuMSA4Ni40IDMxOC4xIDg2LjQgMzMwLjYgNDIuNyAzMjAuNSA0Mi43Ii8+PHBhdGggY2xhc3M9InN0MCIgZD0iTTM1My4yIDU1LjNjLTIuNC0xLjQtNS4zLTIuMS04LjYtMi4xcy02LjIuNy04LjYgMi4xYy0yLjQgMS40LTQuMyAzLjQtNS42IDUuOS0xLjMgMi41LTIgNS41LTIgOC45cy43IDYuMyAyIDguOGMxLjMgMi41IDMuMiA0LjUgNS42IDUuOSAyLjQgMS40IDUuMyAyLjEgOC42IDIuMXM2LjItLjcgOC42LTIuMWMyLjQtMS40IDQuMy0zLjQgNS42LTUuOSAxLjMtMi41IDItNS41IDItOC44cy0uNy02LjQtMi04LjljLTEuMy0yLjUtMy4yLTQuNS01LjYtNS45Wk0zNTAuOCA3NS4yYy0uNSAxLjUtMS4zIDIuNy0yLjMgMy41LTEgLjktMi4zIDEuMy0zLjggMS4zcy0yLjgtLjQtMy44LTEuM2MtMS0uOS0xLjgtMi0yLjMtMy41LS41LTEuNS0uOC0zLjItLjgtNS4xcy4zLTMuNi44LTUuMWMuNS0xLjUgMS4zLTIuNyAyLjMtMy41IDEtLjkgMi4zLTEuMyAzLjgtMS4zczIuOC40IDMuOCAxLjNjMSAuOSAxLjggMiAyLjMgMy41LjUgMS41LjggMy4yLjggNS4xcy0uMyAzLjYtLjggNS4xWiIvPjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik0zODIuNCA1My4xYy0xLjggMC0zLjUuNS00LjkgMS42LTEuNCAxLTIuNCAyLjYtMyA0LjZoLS4zdi01LjdoLTguOHYzMi44aDkuMXYtMTguNmMwLTEuMy4zLTIuNS45LTMuNXMxLjQtMS44IDIuNS0yLjRjMS0uNiAyLjItLjkgMy42LS45czEuMyAwIDIuMS4xYy44IDAgMS4zLjIgMS44LjN2LTguMWMtLjQgMC0uOS0uMi0xLjQtLjItLjUgMC0xIDAtMS41IDBaIi8+PHBvbHlnb24gY2xhc3M9InN0MCIgcG9pbnRzPSI0MTkuMiA1My42IDQwOC44IDUzLjYgMzk4LjMgNjYgMzk3LjggNjYgMzk3LjggNDIuNyAzODguNyA0Mi43IDM4OC43IDg2LjQgMzk3LjggODYuNCAzOTcuOCA3NiA0MDAuMyA3My4yIDQwOS4yIDg2LjQgNDE5LjggODYuNCA0MDcuMSA2Ny44IDQxOS4yIDUzLjYiLz48cGF0aCBjbGFzcz0ic3QwIiBkPSJNNDQ1LjcgNzAuNWMtMS42LTEuNS0zLjktMi41LTcuMS0zLjJsLTUuOS0xLjJjLTEuNS0uMy0yLjYtLjgtMy4yLTEuMy0uNi0uNS0uOS0xLjItLjktMnMuNS0xLjggMS41LTIuNGMxLS42IDIuMi0uOSAzLjYtLjlzMiAuMiAyLjcuNWMuOC4zIDEuNC44IDEuOCAxLjQuNS42LjggMS4yLjkgMS45bDguMy0uNWMtLjQtMy0xLjgtNS40LTQuMi03LjItMi40LTEuOC01LjYtMi42LTkuOC0yLjZzLTUuMi40LTcuMyAxLjJjLTIuMS44LTMuNyAyLTQuOCAzLjUtMS4xIDEuNS0xLjcgMy4zLTEuNyA1LjRzLjggNC41IDIuMyA2YzEuNiAxLjYgMy45IDIuNyA3LjEgMy4zbDUuNyAxLjFjMS40LjMgMi41LjcgMy4yIDEuMi43LjUgMSAxLjIgMSAyIDAgMS0uNSAxLjgtMS41IDIuNC0xIC42LTIuMy45LTMuOS45cy0zLS4zLTQtMWMtMS0uNy0xLjctMS43LTItM2wtOC45LjVjLjQgMy4xIDEuOSA1LjYgNC41IDcuNCAyLjYgMS44IDYgMi43IDEwLjQgMi43czUuNC0uNSA3LjUtMS40YzIuMi0uOSAzLjktMi4yIDUuMi0zLjggMS4zLTEuNiAxLjktMy41IDEuOS01LjdzLS44LTQuMy0yLjMtNS43WiIvPjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik04MS43IDI5LjJoLTI5Yy0xNC43IDAtMjYuNyAxMS45LTI2LjcgMjYuN3YyNy4ybDEwLjgtNnYtMjEuMmMwLTguOCA3LjEtMTUuOSAxNS45LTE1LjloMjl2My4zbDE1LjQtOC42LTE1LjQtOC42djMuMloiLz48cGF0aCBjbGFzcz0ic3QwIiBkPSJNODYuMyA3My40YzAgOC44LTcuMSAxNS45LTE1LjkgMTUuOWgtMjl2LTMuM2wtMTUuNCA4LjYgMTUuNCA4LjZ2LTMuMmgyOWMxNC43IDAgMjYuNy0xMS45IDI2LjctMjYuN3YtMjcuMmwtMTAuOCA2djIxLjJaIi8+PHBvbHlnb24gY2xhc3M9InN0MCIgcG9pbnRzPSI1MSA0Ny44IDUxIDgxLjMgNzguNyA2NC42IDUxIDQ3LjgiLz48L3N2Zz4=',
            'primaryColor' => '#2C3E50',
            'description' => 'Online Music Lessons with Video Exchange',
        ],
        'blayze' => [
            'id' => 'blayze',
            'name' => 'Blayze',
            'website' => 'blayze.io',
            'logo' => '/images/logos/blayze.svg',
            'primaryColor' => '#3498DB',
            'description' => 'Private 1:1 Coaching Platform',
        ],
        'faderpro' => [
            'id' => 'faderpro',
            'name' => 'FaderPro',
            'website' => 'faderpro.com',
            'logo' => 'https://alpha.uscreencdn.com/450xnull/4576/uploads/c0e09b4d-7e21-45ed-94a1-19e9d2a097ce.png',
            'primaryColor' => '#9B59B6',
            'description' => 'Music Production Courses',
        ],
    ];

    /**
     * Default system prompts for each brand
     */
    public const DEFAULT_PROMPTS = [
        'truefire' => 'You are an expert music educator creating detailed, professional blog content for intermediate to advanced musicians. Transform the provided video transcript into a comprehensive, well-structured blog article that maintains educational value while being engaging and insightful. Focus on technical accuracy, practical applications, and clear explanations for guitar players.',
        'artistworks' => 'You are an expert music instructor creating engaging blog content for musicians of all levels. Transform the provided video transcript into a comprehensive article that maintains educational value while being accessible and inspiring. Focus on technique, musicianship, and personal growth.',
        'blayze' => 'You are a professional coaching expert creating motivational and educational content. Transform the provided video transcript into an actionable, results-oriented article. Focus on practical tips, performance improvement, and personalized learning strategies.',
        'faderpro' => 'You are a music production expert creating technical and creative content for producers and beatmakers. Transform the provided video transcript into a detailed, hands-on article. Focus on production techniques, workflow optimization, and creative approaches to music creation.',
    ];

    /**
     * Get all settings for a brand as a key-value collection
     */
    public static function getForBrand(string $brandId): Collection
    {
        return static::where('brand_id', $brandId)
            ->pluck('value', 'key');
    }

    /**
     * Get a specific setting for a brand
     */
    public static function get(string $brandId, string $key, ?string $default = null): ?string
    {
        $setting = static::where('brand_id', $brandId)
            ->where('key', $key)
            ->first();

        return $setting?->value ?? $default;
    }

    /**
     * Set a setting for a brand
     */
    public static function set(string $brandId, string $key, string $value): static
    {
        return static::updateOrCreate(
            ['brand_id' => $brandId, 'key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Get the system prompt for a brand
     */
    public static function getSystemPrompt(string $brandId): string
    {
        return static::get($brandId, 'system_prompt', static::DEFAULT_PROMPTS[$brandId] ?? static::DEFAULT_PROMPTS['truefire']);
    }

    /**
     * Get the LLM model for a brand
     */
    public static function getLlmModel(string $brandId): string
    {
        // Use Claude 3.5 Sonnet v1 for on-demand, or v2 requires inference profile
        return static::get($brandId, 'llm_model', 'us.anthropic.claude-haiku-4-5-20251001-v1:0');
    }
}


