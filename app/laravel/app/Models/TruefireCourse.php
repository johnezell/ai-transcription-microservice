<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TruefireCourse extends Model
{
   
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
}