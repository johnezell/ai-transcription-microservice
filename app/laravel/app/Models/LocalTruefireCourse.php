<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocalTruefireCourse extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'local_truefire_courses';

    // Uses default SQLite connection

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'additional_authors',
        'aligned_with_artist',
        'allow_firesale',
        'allow_streaming',
        'artist_per_view_royalty',
        'audio_extraction_preset',
        'author_url',
        'authorid',
        'bigpageurl',
        'changelog',
        'checksum',
        'class',
        'course_size',
        'course_size_hd',
        'document_checksum',
        'document_date',
        'early_access_date',
        'fb_comments_url',
        'fb_like_url',
        'fb_share_url',
        'featured',
        'free_remaining',
        'ios_data',
        'is_camp',
        'is_compilation',
        'is_foundry',
        'is_free',
        'is_hd',
        'is_playstore',
        'jp_course',
        'last_updated',
        'long_description',
        'meta',
        'meta_description',
        'meta_title',
        'moov',
        'mp4_ready',
        'new_till',
        'page_html',
        'page_title',
        'path',
        'perma_link',
        'persona',
        'release_date',
        'sandbox_html',
        'segments_checksum',
        'short_description',
        'soundslice_checksum',
        'staff_pic',
        'status',
        'studio',
        'suppl_cids',
        'title',
        'version',
        'version_date',
        'video_count',
        'workshop_study_guide',
        'youtube_intro_link',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'allow_streaming' => 'boolean',
        'allow_firesale' => 'boolean',
        'is_free' => 'boolean',
        'mp4_ready' => 'boolean',
        'is_compilation' => 'boolean',
        'is_foundry' => 'boolean',
        'is_hd' => 'boolean',
        'is_camp' => 'boolean',
        'is_playstore' => 'boolean',
        'jp_course' => 'boolean',
        'featured' => 'boolean',
        'aligned_with_artist' => 'boolean',
        'artist_per_view_royalty' => 'decimal:4',
        'course_size' => 'integer',
        'course_size_hd' => 'integer',
        'authorid' => 'integer',
        'free_remaining' => 'integer',
        'video_count' => 'integer',
        'version_date' => 'date',
        'new_till' => 'date',
        'release_date' => 'date',
        'early_access_date' => 'date',
        'document_date' => 'date',
        'last_updated' => 'datetime',
        'meta' => 'json',
        'ios_data' => 'json',
        'additional_authors' => 'json',
        'suppl_cids' => 'json',
    ];

    /**
     * Get the audio extraction preset for this course.
     *
     * @return string
     */
    public function getAudioExtractionPreset(): string
    {
        // First check if there's a specific preset set via the pivot table
        $coursePreset = CourseAudioPreset::getPresetForCourse($this->id);
        
        if ($coursePreset) {
            return $coursePreset;
        }
        
        // Fall back to the course's default preset
        return $this->audio_extraction_preset ?? 'balanced';
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
     * Get the channels for the course.
     */
    public function channels()
    {
        return $this->hasMany(LocalTruefireChannel::class, 'courseid', 'id');
    }

    /**
     * Get all segments for the course through channels (includes all segments for backward compatibility).
     */
    public function allSegments()
    {
        return $this->hasManyThrough(
            LocalTruefireSegment::class,
            LocalTruefireChannel::class,
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
            LocalTruefireSegment::class,
            LocalTruefireChannel::class,
            'courseid',   // Foreign key on channels table
            'channel_id', // Foreign key on segments table
            'id',         // Local key on courses table
            'id'          // Local key on channels table
        )->withVideo(); // Apply the scope to filter for segments with valid video fields
    }

    /**
     * Get the audio extraction preset for this course (single relationship).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function audioPreset()
    {
        return $this->hasOne(CourseAudioPreset::class, 'truefire_course_id');
    }

    /**
     * Relationship to course audio presets (multiple).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function audioPresets()
    {
        return $this->hasMany(CourseAudioPreset::class, 'truefire_course_id');
    }

    /**
     * Get the current active audio preset for this course.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function currentAudioPreset()
    {
        return $this->hasOne(CourseAudioPreset::class, 'truefire_course_id')->latest();
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
