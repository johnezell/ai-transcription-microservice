<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class CourseTranscriptionPreset extends Model
{
    /**
     * Uses the default SQLite connection.
     */

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'course_transcription_presets';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'truefire_course_id',
        'transcription_preset',
        'settings',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'settings' => 'array',
        'transcription_preset' => 'string',
    ];

    /**
     * Get the TrueFire course that owns this preset.
     */
    public function truefireCourse(): BelongsTo
    {
        return $this->belongsTo(LocalTruefireCourse::class, 'truefire_course_id');
    }

    /**
     * Get or create a preset for a course with default values.
     *
     * @param int $courseId
     * @param string $defaultPreset
     * @return CourseTranscriptionPreset
     */
    public static function getOrCreateForCourse(int $courseId, string $defaultPreset = 'balanced'): CourseTranscriptionPreset
    {
        return static::firstOrCreate(
            ['truefire_course_id' => $courseId],
            [
                'transcription_preset' => $defaultPreset,
                'settings' => []
            ]
        );
    }

    /**
     * Update the preset for a course.
     *
     * @param int $courseId
     * @param string $preset
     * @param array $settings
     * @return CourseTranscriptionPreset
     */
    public static function updateForCourse(int $courseId, string $preset, array $settings = []): CourseTranscriptionPreset
    {
        $coursePreset = static::getOrCreateForCourse($courseId);
        $coursePreset->update([
            'transcription_preset' => $preset,
            'settings' => array_merge($coursePreset->settings ?? [], $settings)
        ]);
        
        return $coursePreset;
    }

    /**
     * Get the preset for a course, or return default.
     *
     * @param int $courseId
     * @param string $defaultPreset
     * @return string
     */
    public static function getPresetForCourse(int $courseId, string $defaultPreset = 'balanced'): string
    {
        $preset = static::where('truefire_course_id', $courseId)->first();
        return $preset ? $preset->transcription_preset : $defaultPreset;
    }

    /**
     * Get the settings for a course, or return empty array.
     *
     * @param int $courseId
     * @return array
     */
    public static function getSettingsForCourse(int $courseId): array
    {
        $preset = static::where('truefire_course_id', $courseId)->first();
        return $preset ? ($preset->settings ?? []) : [];
    }

    /**
     * Set a preset for a course (works with local course IDs).
     *
     * @param int $courseId
     * @param string $preset
     * @param array $settings
     * @return CourseTranscriptionPreset
     */
    public static function setPresetForCourse(int $courseId, string $preset, array $settings = []): CourseTranscriptionPreset
    {
        return static::updateForCourse($courseId, $preset, $settings);
    }

    /**
     * Check if a preset value is valid.
     *
     * @param string $preset
     * @return bool
     */
    public static function isValidPreset(string $preset): bool
    {
        $availablePresets = config('transcription_presets.presets', []);
        return array_key_exists($preset, $availablePresets);
    }

    /**
     * Get all available presets with descriptions.
     *
     * @return array
     */
    public static function getAvailablePresets(): array
    {
        $presets = config('transcription_presets.presets', []);
        $descriptions = [];
        
        foreach ($presets as $key => $config) {
            $descriptions[$key] = $config['name'] . ' - ' . $config['description'];
        }
        
        return $descriptions;
    }

    /**
     * Get the configuration for a specific preset.
     *
     * @param string $preset
     * @return array
     * @throws InvalidArgumentException
     */
    public function getPresetConfiguration(string $preset): array
    {
        if (!static::isValidPreset($preset)) {
            throw new InvalidArgumentException("Invalid preset: {$preset}");
        }

        return config("transcription_presets.presets.{$preset}", []);
    }

    /**
     * Get the Whisper model for the current preset.
     *
     * @return string
     * @throws InvalidArgumentException
     */
    public function getWhisperModel(): string
    {
        $config = $this->getPresetConfiguration($this->transcription_preset);
        
        if (!isset($config['whisper_model'])) {
            throw new InvalidArgumentException("Whisper model not configured for preset: {$this->transcription_preset}");
        }

        return $config['whisper_model'];
    }

    /**
     * Get the processing parameters for the current preset.
     *
     * @return array
     * @throws InvalidArgumentException
     */
    public function getProcessingParameters(): array
    {
        $config = $this->getPresetConfiguration($this->transcription_preset);
        
        // Extract processing parameters from config
        $parameters = [
            'temperature' => $config['temperature'] ?? 0.0,
            'best_of' => $config['best_of'] ?? 1,
            'beam_size' => $config['beam_size'] ?? 1,
            'patience' => $config['patience'] ?? 1.0,
            'length_penalty' => $config['length_penalty'] ?? 1.0,
            'suppress_tokens' => $config['suppress_tokens'] ?? [-1],
            'initial_prompt' => $config['initial_prompt'] ?? '',
            'condition_on_previous_text' => $config['condition_on_previous_text'] ?? true,
            'fp16' => $config['fp16'] ?? true,
            'compression_ratio_threshold' => $config['compression_ratio_threshold'] ?? 2.4,
            'logprob_threshold' => $config['logprob_threshold'] ?? -1.0,
            'no_speech_threshold' => $config['no_speech_threshold'] ?? 0.6,
            'word_timestamps' => $config['word_timestamps'] ?? false,
            'prepend_punctuations' => $config['prepend_punctuations'] ?? '"\'([{-',
            'append_punctuations' => $config['append_punctuations'] ?? '"\'.,!?:;)}]',
        ];

        // Merge with any custom settings from the database
        if (!empty($this->settings)) {
            $parameters = array_merge($parameters, $this->settings);
        }

        return $parameters;
    }

    /**
     * Get the expected accuracy for the current preset.
     *
     * @return string
     */
    public function getExpectedAccuracy(): string
    {
        $config = $this->getPresetConfiguration($this->transcription_preset);
        return $config['expected_accuracy'] ?? 'Unknown';
    }

    /**
     * Get the estimated processing time for the current preset.
     *
     * @return string
     */
    public function getEstimatedProcessingTime(): string
    {
        $config = $this->getPresetConfiguration($this->transcription_preset);
        return $config['estimated_processing_time'] ?? 'Unknown';
    }

    /**
     * Get the use case description for the current preset.
     *
     * @return string
     */
    public function getUseCase(): string
    {
        $config = $this->getPresetConfiguration($this->transcription_preset);
        return $config['use_case'] ?? 'General transcription';
    }

    /**
     * Get the supported output formats for the current preset.
     *
     * @return array
     */
    public function getSupportedOutputFormats(): array
    {
        $config = $this->getPresetConfiguration($this->transcription_preset);
        return $config['output_format'] ?? ['txt'];
    }

    /**
     * Check if the current preset supports word timestamps.
     *
     * @return bool
     */
    public function supportsWordTimestamps(): bool
    {
        $config = $this->getPresetConfiguration($this->transcription_preset);
        return $config['word_timestamps'] ?? false;
    }

    /**
     * Check if the current preset includes confidence scores.
     *
     * @return bool
     */
    public function includesConfidenceScores(): bool
    {
        $config = $this->getPresetConfiguration($this->transcription_preset);
        return $config['include_confidence_scores'] ?? false;
    }

    /**
     * Get the maximum audio duration supported by the current preset.
     *
     * @return int Duration in seconds
     */
    public function getMaxAudioDuration(): int
    {
        $config = $this->getPresetConfiguration($this->transcription_preset);
        return $config['max_audio_duration'] ?? 3600; // Default 1 hour
    }

    /**
     * Get the supported audio formats for the current preset.
     *
     * @return array
     */
    public function getSupportedAudioFormats(): array
    {
        $config = $this->getPresetConfiguration($this->transcription_preset);
        return $config['supported_formats'] ?? ['wav', 'mp3'];
    }

    /**
     * Get model specifications for the current preset's Whisper model.
     *
     * @return array
     */
    public function getModelSpecifications(): array
    {
        $whisperModel = $this->getWhisperModel();
        return config("transcription_presets.models.{$whisperModel}", []);
    }

    /**
     * Validate audio file against preset requirements.
     *
     * @param string $filePath
     * @param int $duration Duration in seconds
     * @param string $format File format
     * @return bool
     */
    public function validateAudioFile(string $filePath, int $duration, string $format): bool
    {
        $config = $this->getPresetConfiguration($this->transcription_preset);
        
        // Check duration limits
        $minDuration = $config['min_audio_duration'] ?? 1;
        $maxDuration = $config['max_audio_duration'] ?? 3600;
        
        if ($duration < $minDuration || $duration > $maxDuration) {
            return false;
        }
        
        // Check supported formats
        $supportedFormats = $config['supported_formats'] ?? ['wav', 'mp3'];
        if (!in_array(strtolower($format), $supportedFormats)) {
            return false;
        }
        
        // Check file size if file exists
        if (file_exists($filePath)) {
            $fileSize = filesize($filePath);
            $maxFileSize = config('transcription_presets.validation.max_file_size', 500 * 1024 * 1024);
            $minFileSize = config('transcription_presets.validation.min_file_size', 1024);
            
            if ($fileSize > $maxFileSize || $fileSize < $minFileSize) {
                return false;
            }
        }
        
        return true;
    }
}