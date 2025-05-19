<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TranscriptionPreset extends Model
{
    use HasFactory;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'model',
        'language',
        'configuration',
        'old_options',  // Kept for backward compatibility during migration
        'is_default',
        'is_active',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'configuration' => 'array',
        'old_options' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
    
    /**
     * Get the transcription configuration.
     * 
     * @return array
     */
    public function getTranscriptionConfigAttribute()
    {
        return $this->configuration['transcription'] ?? [];
    }
    
    /**
     * Get the audio extraction configuration.
     * 
     * @return array
     */
    public function getAudioConfigAttribute()
    {
        return $this->configuration['audio'] ?? [];
    }
    
    /**
     * Get the terminology recognition configuration.
     * 
     * @return array
     */
    public function getTerminologyConfigAttribute()
    {
        return $this->configuration['terminology'] ?? [];
    }
    
    /**
     * Create a default preset configuration.
     * 
     * @return array
     */
    public static function getDefaultConfiguration()
    {
        return [
            'transcription' => [
                'initial_prompt' => null,
                'temperature' => 0,
                'word_timestamps' => true,
                'condition_on_previous_text' => false,
            ],
            'audio' => [
                'sample_rate' => '16000',
                'channels' => '1',
                'audio_codec' => 'pcm_s16le',
                'noise_reduction' => false,
                'normalize_audio' => false,
                'volume_boost' => 0,
            ],
            'terminology' => [
                'extraction_method' => 'regex',
                'case_sensitive' => false,
                'min_term_frequency' => 1,
                'spacy_model' => 'en_core_web_sm',
                'use_lemmatization' => true,
                'include_uncategorized' => false,
            ]
        ];
    }
    
    /**
     * Scope a query to only include active presets.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    /**
     * Scope a query to only include the default preset.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
    
    /**
     * Get videos using this preset.
     */
    public function videos()
    {
        return $this->hasMany(Video::class, 'preset_id');
    }
    
    /**
     * For backward compatibility - get the old options format
     */
    public function getOptionsAttribute()
    {
        // First try to use old_options if they exist
        if (!empty($this->old_options)) {
            return $this->old_options;
        }
        
        // Otherwise convert from new configuration format
        $options = [];
        if (isset($this->configuration['transcription'])) {
            $options = $this->configuration['transcription'];
        }
        
        return $options;
    }
}
