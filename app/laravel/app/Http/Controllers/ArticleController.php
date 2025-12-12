<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\BrandSetting;
use App\Models\Video;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArticleController extends Controller
{
    /**
     * Brand selection page (entry point)
     */
    public function selectBrand(): Response
    {
        return Inertia::render('Articles/SelectBrand', [
            'brands' => BrandSetting::BRANDS,
        ]);
    }

    /**
     * Display article list
     */
    public function index(Request $request): Response
    {
        $brandId = $request->query('brandId', 'truefire');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 12;

        $query = Article::forBrand($brandId)->ready();

        $articles = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return Inertia::render('Articles/Index', [
            'articles' => $articles,
            'currentBrand' => $brandId,
            'brands' => BrandSetting::BRANDS,
        ]);
    }

    /**
     * Show create article form
     */
    public function create(Request $request): Response
    {
        $brandId = $request->query('brandId', 'truefire');

        // Get videos with transcripts that can be used to generate articles
        $videosWithTranscripts = Video::whereIn('status', ['transcribed', 'completed'])
            ->whereNotNull('transcript_path')
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get(['id', 'original_filename', 'status', 'created_at', 'formatted_duration']);

        return Inertia::render('Articles/Create', [
            'currentBrand' => $brandId,
            'brands' => BrandSetting::BRANDS,
            'videosWithTranscripts' => $videosWithTranscripts,
        ]);
    }

    /**
     * Show single article
     */
    public function show(Article $article): Response
    {
        $article->load(['comments.replies', 'video']);

        return Inertia::render('Articles/Show', [
            'article' => $article,
            'brands' => BrandSetting::BRANDS,
        ]);
    }

    /**
     * Edit article
     */
    public function edit(Article $article): Response
    {
        $article->load(['comments.replies', 'video']);

        return Inertia::render('Articles/Edit', [
            'article' => $article,
            'brands' => BrandSetting::BRANDS,
        ]);
    }

    /**
     * Article settings page
     */
    public function settings(Request $request): Response
    {
        $brandId = $request->query('brandId', 'truefire');
        $settings = BrandSetting::getForBrand($brandId);

        // Add defaults if not set
        $settingsArray = $settings->toArray();
        if (!isset($settingsArray['llm_model'])) {
            $settingsArray['llm_model'] = BrandSetting::getLlmModel($brandId);
        }
        if (!isset($settingsArray['system_prompt'])) {
            $settingsArray['system_prompt'] = BrandSetting::getSystemPrompt($brandId);
        }

        return Inertia::render('Articles/Settings', [
            'currentBrand' => $brandId,
            'brands' => BrandSetting::BRANDS,
            'settings' => $settingsArray,
            'availableModels' => [
                'us.anthropic.claude-haiku-4-5-20251001-v1:0' => 'Claude Haiku 4.5 (Recommended)',
                'us.anthropic.claude-sonnet-4-5-20250929-v1:0' => 'Claude Sonnet 4.5',
            ],
        ]);
    }
}


