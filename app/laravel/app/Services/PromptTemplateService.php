<?php

namespace App\Services;

use App\Models\LocalTruefireCourse;
use Illuminate\Support\Facades\Log;

class PromptTemplateService
{
    /**
     * Render a mustache template with provided context data.
     *
     * @param string $template
     * @param array $context
     * @return string
     */
    public function render(string $template, array $context = []): string
    {
        try {
            // Basic mustache-style template rendering
            // Handle {{#variable}} conditional blocks (if variable exists and is truthy)
            $template = preg_replace_callback('/\{\{#(\w+)\}\}(.*?)\{\{\/\1\}\}/s', function ($matches) use ($context) {
                $variable = $matches[1];
                $content = $matches[2];
                
                if (!empty($context[$variable])) {
                    // Replace {{variable}} with actual value in the content
                    $processedContent = str_replace("{{$variable}}", $context[$variable], $content);
                    // Also handle any other variable replacements in the content
                    $processedContent = preg_replace_callback('/\{\{(\w+)\}\}/', function ($innerMatches) use ($context) {
                        $innerVariable = $innerMatches[1];
                        return $context[$innerVariable] ?? '';
                    }, $processedContent);
                    return $processedContent;
                }
                
                return '';
            }, $template);
            
            // Handle {{^variable}} conditional blocks (if variable doesn't exist or is falsy)
            $template = preg_replace_callback('/\{\{\^(\w+)\}\}(.*?)\{\{\/\1\}\}/s', function ($matches) use ($context) {
                $variable = $matches[1];
                $content = $matches[2];
                
                if (empty($context[$variable])) {
                    return $content;
                }
                
                return '';
            }, $template);
            
            // Handle simple {{variable}} replacements
            $template = preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($context) {
                $variable = $matches[1];
                return $context[$variable] ?? '';
            }, $template);
            
            // Clean up any extra whitespace
            $template = preg_replace('/\s+/', ' ', $template);
            $template = trim($template);
            
            return $template;
            
        } catch (\Exception $e) {
            Log::error('Error rendering prompt template', [
                'template' => $template,
                'context' => $context,
                'error' => $e->getMessage()
            ]);
            
            // Return the original template as fallback
            return $template;
        }
    }
    
    /**
     * Build context data for a course and segment.
     *
     * @param LocalTruefireCourse|null $course
     * @param object|null $segment
     * @param array $additionalContext
     * @return array
     */
    public function buildContext(?LocalTruefireCourse $course = null, ?object $segment = null, array $additionalContext = []): array
    {
        $context = [];
        
        // Course-related variables
        if ($course) {
            $context['course_title'] = $course->title ?? null;
            $context['course_difficulty'] = $course->difficulty_level ?? null;
            $context['instructor_name'] = $course->instructor_name ?? null;
            $context['instructor_credentials'] = $course->instructor_credentials ?? null;
            $context['musical_genre'] = $course->genre ?? null;
            $context['skill_level'] = $course->skill_level ?? null;
            $context['lesson_series'] = $course->series_name ?? null;
            $context['educational_objectives'] = $course->objectives ?? null;
            $context['equipment_used'] = $course->equipment ?? null;
            
            // Attempt to get previous and upcoming topics from course structure
            $context['previous_topics'] = $this->getPreviousTopics($course, $segment);
            $context['upcoming_topics'] = $this->getUpcomingTopics($course, $segment);
        }
        
        // Segment-related variables
        if ($segment) {
            $context['segment_title'] = $segment->title ?? null;
            $context['lesson_topic'] = $segment->topic ?? $segment->title ?? null;
            $context['lesson_duration'] = $segment->duration_minutes ?? null;
            $context['specific_techniques'] = $segment->techniques ?? null;
            
            // Override course values with segment-specific ones if available
            if (!empty($segment->genre)) {
                $context['musical_genre'] = $segment->genre;
            }
            if (!empty($segment->skill_level)) {
                $context['skill_level'] = $segment->skill_level;
            }
            if (!empty($segment->objectives)) {
                $context['educational_objectives'] = $segment->objectives;
            }
        }
        
        // Merge any additional context provided
        $context = array_merge($context, $additionalContext);
        
        // Remove null values to clean up the context
        $context = array_filter($context, function ($value) {
            return $value !== null && $value !== '';
        });
        
        return $context;
    }
    
    /**
     * Get context data from a TrueFire course by ID.
     *
     * @param int $courseId
     * @param int|null $segmentId
     * @param array $additionalContext
     * @return array
     */
    public function buildContextFromIds(int $courseId, ?int $segmentId = null, array $additionalContext = []): array
    {
        try {
            $course = LocalTruefireCourse::find($courseId);
            $segment = null;
            
            if ($segmentId && $course) {
                // Find the segment within the course
                $segment = $course->channels()
                    ->with('segments')
                    ->get()
                    ->flatMap(function ($channel) {
                        return $channel->segments;
                    })
                    ->where('id', $segmentId)
                    ->first();
            }
            
            return $this->buildContext($course, $segment, $additionalContext);
            
        } catch (\Exception $e) {
            Log::error('Error building context from IDs', [
                'course_id' => $courseId,
                'segment_id' => $segmentId,
                'error' => $e->getMessage()
            ]);
            
            return $additionalContext;
        }
    }
    
    /**
     * Render a preset prompt with context data.
     *
     * @param string $presetName
     * @param array $context
     * @return string
     */
    public function renderPresetPrompt(string $presetName, array $context = []): string
    {
        $presets = config('transcription_presets.presets', []);
        
        if (!isset($presets[$presetName])) {
            Log::warning('Unknown preset for prompt rendering', ['preset' => $presetName]);
            return '';
        }
        
        $template = $presets[$presetName]['initial_prompt'] ?? '';
        
        return $this->render($template, $context);
    }
    
    /**
     * Get available template variables with their metadata.
     *
     * @return array
     */
    public function getAvailableVariables(): array
    {
        return config('transcription_presets.template_variables', []);
    }
    
    /**
     * Get template variable categories.
     *
     * @return array
     */
    public function getVariableCategories(): array
    {
        return config('transcription_presets.template_categories', []);
    }
    
    /**
     * Extract template variables used in a template string.
     *
     * @param string $template
     * @return array
     */
    public function extractUsedVariables(string $template): array
    {
        $variables = [];
        
        // Find all {{variable}} patterns
        if (preg_match_all('/\{\{[#\^]?(\w+)\}\}/', $template, $matches)) {
            $variables = array_unique($matches[1]);
        }
        
        return $variables;
    }
    
    /**
     * Validate that a template uses only defined variables.
     *
     * @param string $template
     * @return array ['valid' => bool, 'undefined_variables' => array]
     */
    public function validateTemplate(string $template): array
    {
        $usedVariables = $this->extractUsedVariables($template);
        $availableVariables = array_keys($this->getAvailableVariables());
        
        $undefinedVariables = array_diff($usedVariables, $availableVariables);
        
        return [
            'valid' => empty($undefinedVariables),
            'undefined_variables' => $undefinedVariables,
            'used_variables' => $usedVariables,
        ];
    }
    
    /**
     * Generate a preview of rendered template with sample data.
     *
     * @param string $template
     * @param array $customContext
     * @return string
     */
    public function generatePreview(string $template, array $customContext = []): string
    {
        // Use sample data from variable definitions
        $sampleContext = [];
        $variables = $this->getAvailableVariables();
        
        foreach ($variables as $key => $config) {
            $sampleContext[$key] = $config['example'] ?? "{{$key}}";
        }
        
        // Override with any custom context provided
        $sampleContext = array_merge($sampleContext, $customContext);
        
        return $this->render($template, $sampleContext);
    }
    
    /**
     * Get topics from previous segments in the course.
     *
     * @param LocalTruefireCourse $course
     * @param object|null $currentSegment
     * @return string|null
     */
    private function getPreviousTopics(LocalTruefireCourse $course, ?object $currentSegment = null): ?string
    {
        try {
            if (!$currentSegment) {
                return null;
            }
            
            // This would need to be implemented based on your course/segment structure
            // For now, return null as this requires database queries
            return null;
            
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get topics from upcoming segments in the course.
     *
     * @param LocalTruefireCourse $course
     * @param object|null $currentSegment
     * @return string|null
     */
    private function getUpcomingTopics(LocalTruefireCourse $course, ?object $currentSegment = null): ?string
    {
        try {
            if (!$currentSegment) {
                return null;
            }
            
            // This would need to be implemented based on your course/segment structure
            // For now, return null as this requires database queries
            return null;
            
        } catch (\Exception $e) {
            return null;
        }
    }
} 