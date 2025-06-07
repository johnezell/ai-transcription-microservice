<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Exception;

class TruefireCourse extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;
   
    /**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = 'truefire';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'courses';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        // Add your fillable attributes here based on the actual table structure
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        // Add your casts here based on the actual table structure
    ];

    /**
     * READ-ONLY MODEL PROTECTION
     * The following methods prevent any modifications to the TrueFire production data
     */

    /**
     * Prevent saving the model.
     *
     * @param array $options
     * @return bool
     * @throws Exception
     */
    public function save(array $options = [])
    {
        throw new Exception('TruefireCourse model is read-only. Cannot save changes to production data.');
    }

    /**
     * Prevent updating the model.
     *
     * @param array $attributes
     * @param array $options
     * @return bool
     * @throws Exception
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new Exception('TruefireCourse model is read-only. Cannot update production data.');
    }

    /**
     * Prevent deleting the model.
     *
     * @return bool|null
     * @throws Exception
     */
    public function delete()
    {
        throw new Exception('TruefireCourse model is read-only. Cannot delete production data.');
    }

    /**
     * Prevent force deleting the model.
     *
     * @return bool|null
     * @throws Exception
     */
    public function forceDelete()
    {
        throw new Exception('TruefireCourse model is read-only. Cannot force delete production data.');
    }

    /**
     * Prevent restoring the model.
     *
     * @return bool|null
     * @throws Exception
     */
    public function restore()
    {
        throw new Exception('TruefireCourse model is read-only. Cannot restore production data.');
    }

    /**
     * Prevent creating new instances.
     *
     * @param array $attributes
     * @return static
     * @throws Exception
     */
    public static function create(array $attributes = [])
    {
        throw new Exception('TruefireCourse model is read-only. Cannot create new production data.');
    }

    /**
     * Prevent inserting new records.
     *
     * @param array $values
     * @return bool
     * @throws Exception
     */
    public function insert(array $values)
    {
        throw new Exception('TruefireCourse model is read-only. Cannot insert production data.');
    }

    /**
     * Prevent truncating the table.
     *
     * @return void
     * @throws Exception
     */
    public function truncate()
    {
        throw new Exception('TruefireCourse model is read-only. Cannot truncate production data.');
    }

    /**
     * Prevent mass updates.
     *
     * @param array $attributes
     * @return int
     * @throws Exception
     */
    public function massUpdate(array $attributes)
    {
        throw new Exception('TruefireCourse model is read-only. Cannot perform mass updates on production data.');
    }

    /**
     * Get the channels for the course.
     */
    public function channels()
    {
        return $this->hasMany(Channel::class, 'courseid', 'id');
    }

    /**
     * Get all segments for the course through channels (includes all segments for backward compatibility).
     */
    public function allSegments()
    {
        return $this->hasManyThrough(
            Segment::class,
            Channel::class,
            'courseid',   // Foreign key on channels table
            'channel_id', // Foreign key on segments table
            'id',         // Local key on courses table
            'id'          // Local key on channels table
        );
    }

    /**
     * Get segments with valid video fields for the course through channels.
     * Only includes segments that have a valid video field (not null, not empty, starts with 'mp4:').
     */
    public function segments()
    {
        return $this->hasManyThrough(
            Segment::class,
            Channel::class,
            'courseid',   // Foreign key on channels table
            'channel_id', // Foreign key on segments table
            'id',         // Local key on courses table
            'id'          // Local key on channels table
        )->withVideo(); // Apply the scope to filter for segments with valid video fields
    }

    /**
     * Get the audio extraction preset for this course.
     */
    public function audioPreset()
    {
        return $this->hasOne(CourseAudioPreset::class, 'truefire_course_id');
    }

    /**
     * Get the audio extraction preset value for this course.
     *
     * @param string $default
     * @return string
     */
    public function getAudioExtractionPreset(string $default = 'balanced'): string
    {
        return CourseAudioPreset::getPresetForCourse($this->id, $default);
    }

    /**
     * Get the audio extraction settings for this course.
     *
     * @return array
     */
    public function getAudioExtractionSettings(): array
    {
        return CourseAudioPreset::getSettingsForCourse($this->id);
    }

    /**
     * Set the audio extraction preset for this course.
     *
     * @param string $preset
     * @param array $settings
     * @return CourseAudioPreset
     */
    public function setAudioExtractionPreset(string $preset, array $settings = []): CourseAudioPreset
    {
        return CourseAudioPreset::updateForCourse($this->id, $preset, $settings);
    }
}