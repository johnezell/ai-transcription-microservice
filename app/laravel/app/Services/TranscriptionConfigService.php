<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;

class TranscriptionConfigService
{
    /**
     * Get all transcription options.
     *
     * @return array
     */
    public function getAllOptions()
    {
        return Config::get('transcription.options');
    }
    
    /**
     * Get transcription options.
     *
     * @return array
     */
    public function getTranscriptionOptions()
    {
        return Config::get('transcription.options.transcription');
    }
    
    /**
     * Get audio extraction options.
     *
     * @return array
     */
    public function getAudioExtractionOptions()
    {
        return Config::get('transcription.options.audio');
    }
    
    /**
     * Get terminology recognition options.
     *
     * @return array
     */
    public function getTerminologyOptions()
    {
        return Config::get('transcription.options.terminology');
    }
    
    /**
     * Get model options for display.
     *
     * @return array
     */
    public function getModelOptions()
    {
        $labels = Config::get('transcription.model_labels');
        return collect($labels)->map(function ($label, $value) {
            return [
                'value' => $value,
                'label' => $label,
            ];
        })->values()->all();
    }
    
    /**
     * Get language options for display.
     *
     * @return array
     */
    public function getLanguageOptions()
    {
        return Config::get('transcription.languages');
    }
    
    /**
     * Generate default configuration values.
     *
     * @return array
     */
    public function getDefaultConfiguration()
    {
        $config = [
            'transcription' => [],
            'audio' => [],
            'terminology' => [],
        ];
        
        // Extract defaults from transcription options
        foreach (['common', 'advanced'] as $section) {
            $options = Config::get("transcription.options.transcription.{$section}", []);
            foreach ($options as $key => $option) {
                $config['transcription'][$key] = $option['default'] ?? null;
            }
        }
        
        // Extract defaults from audio options
        foreach (['common', 'advanced'] as $section) {
            $options = Config::get("transcription.options.audio.{$section}", []);
            foreach ($options as $key => $option) {
                $config['audio'][$key] = $option['default'] ?? null;
            }
        }
        
        // Extract defaults from terminology options
        foreach (['common', 'advanced'] as $section) {
            $options = Config::get("transcription.options.terminology.{$section}", []);
            foreach ($options as $key => $option) {
                $config['terminology'][$key] = $option['default'] ?? null;
            }
        }
        
        return $config;
    }
} 