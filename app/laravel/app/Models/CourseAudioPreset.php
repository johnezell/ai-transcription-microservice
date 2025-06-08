<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseAudioPreset extends Model
{
    /**
     * Uses the default SQLite connection.
     */

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'course_audio_presets';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'truefire_course_id',
        'audio_extraction_preset',
        'settings',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'settings' => 'array',
        'audio_extraction_preset' => 'string',
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
     * @return CourseAudioPreset
     */
    public static function getOrCreateForCourse(int $courseId, string $defaultPreset = 'balanced'): CourseAudioPreset
    {
        return static::firstOrCreate(
            ['truefire_course_id' => $courseId],
            [
                'audio_extraction_preset' => $defaultPreset,
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
     * @return CourseAudioPreset
     */
    public static function updateForCourse(int $courseId, string $preset, array $settings = []): CourseAudioPreset
    {
        $coursePreset = static::getOrCreateForCourse($courseId);
        $coursePreset->update([
            'audio_extraction_preset' => $preset,
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
        return $preset ? $preset->audio_extraction_preset : $defaultPreset;
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
     * @return CourseAudioPreset
     */
    public static function setPresetForCourse(int $courseId, string $preset, array $settings = []): CourseAudioPreset
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
        return in_array($preset, ['fast', 'balanced', 'high', 'premium']);
    }

    /**
     * Get all available presets with descriptions.
     *
     * @return array
     */
    public static function getAvailablePresets(): array
    {
        return [
            'fast' => 'Fast - Quick processing with basic quality',
            'balanced' => 'Balanced - Good quality with reasonable processing time',
            'high' => 'High - High quality with longer processing time',
            'premium' => 'Premium - Maximum quality with extended processing time'
        ];
    }
}