<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PromptTemplateService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TranscriptionPresetsController extends Controller
{
    protected PromptTemplateService $templateService;
    
    public function __construct(PromptTemplateService $templateService)
    {
        $this->templateService = $templateService;
    }

    /**
     * Get all available transcription presets with their configurations
     * including the Whisper initial prompts.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Get preset configurations from config file
            $presetConfigs = config('transcription_presets.presets', []);
            $defaults = config('transcription_presets.defaults', []);
            
            // Format the response with essential information for the UI
            $formattedPresets = [];
            
            foreach ($presetConfigs as $presetKey => $config) {
                $formattedPresets[$presetKey] = [
                    'key' => $presetKey,
                    'name' => $config['name'] ?? ucfirst($presetKey),
                    'description' => $config['description'] ?? '',
                    'use_case' => $config['use_case'] ?? '',
                    
                    // Whisper model configuration
                    'whisper_model' => $config['whisper_model'] ?? 'base',
                    'model_size' => $config['model_size'] ?? 'Unknown',
                    'vram_requirement' => $config['vram_requirement'] ?? 'Unknown',
                    
                    // Processing parameters (the key ones for UX display)
                    'initial_prompt' => $config['initial_prompt'] ?? '',
                    'temperature' => $config['temperature'] ?? 0.0,
                    'word_timestamps' => $config['word_timestamps'] ?? false,
                    'condition_on_previous_text' => $config['condition_on_previous_text'] ?? true,
                    
                    // Quality and performance info
                    'expected_accuracy' => $config['expected_accuracy'] ?? 'Unknown',
                    'estimated_processing_time' => $config['estimated_processing_time'] ?? 'Unknown',
                    'relative_speed' => $config['relative_speed'] ?? 'Unknown',
                    'cpu_usage' => $config['cpu_usage'] ?? 'Unknown',
                    'memory_usage' => $config['memory_usage'] ?? 'Unknown',
                    
                    // Output configuration
                    'output_format' => $config['output_format'] ?? ['txt'],
                    'include_confidence_scores' => $config['include_confidence_scores'] ?? false,
                    'include_speaker_detection' => $config['include_speaker_detection'] ?? false,
                ];
            }
            
            return response()->json([
                'success' => true,
                'presets' => $formattedPresets,
                'defaults' => $defaults,
                'count' => count($formattedPresets)
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve transcription presets',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    
    /**
     * Get configuration for a specific preset.
     *
     * @param Request $request
     * @param string $preset
     * @return JsonResponse
     */
    public function show(Request $request, string $preset): JsonResponse
    {
        try {
            // Validate preset exists
            $presetConfigs = config('transcription_presets.presets', []);
            
            if (!array_key_exists($preset, $presetConfigs)) {
                return response()->json([
                    'success' => false,
                    'message' => "Preset '{$preset}' not found",
                    'available_presets' => array_keys($presetConfigs)
                ], 404);
            }
            
            $config = $presetConfigs[$preset];
            
            // Format detailed configuration for single preset
            $formattedConfig = [
                'key' => $preset,
                'name' => $config['name'] ?? ucfirst($preset),
                'description' => $config['description'] ?? '',
                'use_case' => $config['use_case'] ?? '',
                
                // Full Whisper configuration
                'whisper_configuration' => [
                    'model' => $config['whisper_model'] ?? 'base',
                    'model_size' => $config['model_size'] ?? 'Unknown',
                    'vram_requirement' => $config['vram_requirement'] ?? 'Unknown',
                    'initial_prompt' => $config['initial_prompt'] ?? '',
                    'temperature' => $config['temperature'] ?? 0.0,
                    'best_of' => $config['best_of'] ?? 1,
                    'beam_size' => $config['beam_size'] ?? 1,
                    'patience' => $config['patience'] ?? 1.0,
                    'length_penalty' => $config['length_penalty'] ?? 1.0,
                    'suppress_tokens' => $config['suppress_tokens'] ?? [-1],
                    'condition_on_previous_text' => $config['condition_on_previous_text'] ?? true,
                    'word_timestamps' => $config['word_timestamps'] ?? false,
                    'fp16' => $config['fp16'] ?? true,
                    'compression_ratio_threshold' => $config['compression_ratio_threshold'] ?? 2.4,
                    'logprob_threshold' => $config['logprob_threshold'] ?? -1.0,
                    'no_speech_threshold' => $config['no_speech_threshold'] ?? 0.6,
                ],
                
                // Performance characteristics
                'performance' => [
                    'expected_accuracy' => $config['expected_accuracy'] ?? 'Unknown',
                    'estimated_processing_time' => $config['estimated_processing_time'] ?? 'Unknown',
                    'relative_speed' => $config['relative_speed'] ?? 'Unknown',
                    'cpu_usage' => $config['cpu_usage'] ?? 'Unknown',
                    'memory_usage' => $config['memory_usage'] ?? 'Unknown',
                ],
                
                // Output configuration
                'output' => [
                    'formats' => $config['output_format'] ?? ['txt'],
                    'include_confidence_scores' => $config['include_confidence_scores'] ?? false,
                    'include_speaker_detection' => $config['include_speaker_detection'] ?? false,
                    'timestamp_configuration' => [
                        'word_timestamps' => $config['word_timestamps'] ?? false,
                        'prepend_punctuations' => $config['prepend_punctuations'] ?? '"\'([{-',
                        'append_punctuations' => $config['append_punctuations'] ?? '"\'.,!?:;)}]',
                    ]
                ],
                
                // Validation rules
                'validation' => [
                    'min_audio_duration' => $config['min_audio_duration'] ?? 1,
                    'max_audio_duration' => $config['max_audio_duration'] ?? 3600,
                    'supported_formats' => $config['supported_formats'] ?? ['wav', 'mp3', 'mp4'],
                ]
            ];
            
            return response()->json([
                'success' => true,
                'preset' => $formattedConfig
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve preset configuration',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    
    /**
     * Get available template variables for mustache templating.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getTemplateVariables(Request $request): JsonResponse
    {
        try {
            $variables = $this->templateService->getAvailableVariables();
            $categories = $this->templateService->getVariableCategories();
            
            return response()->json([
                'success' => true,
                'variables' => $variables,
                'categories' => $categories,
                'count' => count($variables)
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve template variables',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    
    /**
     * Render a preset prompt with context data.
     *
     * @param Request $request
     * @param string $preset
     * @return JsonResponse
     */
    public function renderPrompt(Request $request, string $preset): JsonResponse
    {
        try {
            // Validate preset exists
            $presetConfigs = config('transcription_presets.presets', []);
            
            if (!array_key_exists($preset, $presetConfigs)) {
                return response()->json([
                    'success' => false,
                    'message' => "Preset '{$preset}' not found",
                    'available_presets' => array_keys($presetConfigs)
                ], 404);
            }
            
            // Get context data from request
            $context = $request->input('context', []);
            $courseId = $request->input('course_id');
            $segmentId = $request->input('segment_id');
            
            // Build context from course/segment if provided
            if ($courseId) {
                $context = array_merge(
                    $this->templateService->buildContextFromIds($courseId, $segmentId),
                    $context
                );
            }
            
            // Get original template
            $template = $presetConfigs[$preset]['initial_prompt'] ?? '';
            
            // Render the template
            $renderedPrompt = $this->templateService->render($template, $context);
            
            // Validate template
            $validation = $this->templateService->validateTemplate($template);
            
            return response()->json([
                'success' => true,
                'preset' => $preset,
                'template' => $template,
                'rendered_prompt' => $renderedPrompt,
                'context' => $context,
                'validation' => $validation,
                'used_variables' => $this->templateService->extractUsedVariables($template)
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to render prompt template',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    
    /**
     * Generate a preview of a preset prompt with sample data.
     *
     * @param Request $request
     * @param string $preset
     * @return JsonResponse
     */
    public function previewPrompt(Request $request, string $preset): JsonResponse
    {
        try {
            // Validate preset exists
            $presetConfigs = config('transcription_presets.presets', []);
            
            if (!array_key_exists($preset, $presetConfigs)) {
                return response()->json([
                    'success' => false,
                    'message' => "Preset '{$preset}' not found",
                    'available_presets' => array_keys($presetConfigs)
                ], 404);
            }
            
            // Get custom context if provided
            $customContext = $request->input('context', []);
            
            // Get template
            $template = $presetConfigs[$preset]['initial_prompt'] ?? '';
            
            // Generate preview with sample data
            $preview = $this->templateService->generatePreview($template, $customContext);
            
            // Get used variables
            $usedVariables = $this->templateService->extractUsedVariables($template);
            $availableVariables = $this->templateService->getAvailableVariables();
            
            // Build variable details for the used variables
            $variableDetails = [];
            foreach ($usedVariables as $variable) {
                if (isset($availableVariables[$variable])) {
                    $variableDetails[$variable] = $availableVariables[$variable];
                }
            }
            
            return response()->json([
                'success' => true,
                'preset' => $preset,
                'template' => $template,
                'preview' => $preview,
                'used_variables' => $usedVariables,
                'variable_details' => $variableDetails,
                'sample_context' => array_filter($this->templateService->getAvailableVariables(), function($var) use ($usedVariables) {
                    return in_array(array_search($var, $this->templateService->getAvailableVariables()), $usedVariables);
                })
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate prompt preview',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    
    /**
     * Validate a custom template string.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validateTemplate(Request $request): JsonResponse
    {
        try {
            $template = $request->input('template');
            
            if (!$template) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template is required'
                ], 400);
            }
            
            $validation = $this->templateService->validateTemplate($template);
            $usedVariables = $this->templateService->extractUsedVariables($template);
            $availableVariables = $this->templateService->getAvailableVariables();
            
            // Generate preview
            $preview = $this->templateService->generatePreview($template);
            
            return response()->json([
                'success' => true,
                'template' => $template,
                'validation' => $validation,
                'used_variables' => $usedVariables,
                'preview' => $preview,
                'available_variables' => array_keys($availableVariables)
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to validate template',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
} 