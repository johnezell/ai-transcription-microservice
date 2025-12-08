<?php

namespace App\Models\TrueFire;

use Illuminate\Database\Eloquent\Model;

/**
 * TrueFire Legacy Course Model
 * 
 * Connects to the legacy TrueFire database (truefire schema) to access course data.
 * This is a read-only connection for pulling course/video metadata
 * for bulk transcription processing.
 * 
 * Database: truefire.courses
 */
class TrueFireCourse extends Model
{
    protected $connection = 'truefire';
    protected $table = 'courses';
    protected $guarded = [];
    
    // Don't manage timestamps on legacy table
    public $timestamps = false;
    
    /**
     * Get the channels for this course.
     * Channels are in the channels.channels table.
     */
    public function channels()
    {
        return $this->hasMany(TrueFireChannel::class, 'courseid', 'id');
    }
    
    /**
     * Get all segments (videos) for this course through channels.
     */
    public function segments()
    {
        return $this->hasManyThrough(
            TrueFireSegment::class,
            TrueFireChannel::class,
            'courseid',      // Foreign key on channels table
            'channel_id',    // Foreign key on segments table
            'id',            // Local key on courses table
            'id'             // Local key on channels table
        );
    }
    
    /**
     * Get the educator/instructor for this course.
     */
    public function educator()
    {
        return $this->belongsTo(TrueFireEducator::class, 'educator_id', 'id');
    }
    
    /**
     * Scope to only published courses.
     */
    public function scopePublished($query)
    {
        return $query->where('status', 1);
    }
}
