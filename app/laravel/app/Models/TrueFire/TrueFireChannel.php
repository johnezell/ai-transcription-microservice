<?php

namespace App\Models\TrueFire;

use Illuminate\Database\Eloquent\Model;

/**
 * TrueFire Legacy Channel Model
 * 
 * Channels link courses to their video segments.
 * 
 * Database: channels.channels (note: different schema)
 * 
 * A channel represents a collection of video segments for a course.
 */
class TrueFireChannel extends Model
{
    protected $connection = 'truefire';
    protected $table = 'channels.channels'; // Note: schema.table format
    protected $guarded = [];
    
    public $timestamps = false;
    
    /**
     * Get the course this channel belongs to.
     */
    public function course()
    {
        return $this->belongsTo(TrueFireCourse::class, 'courseid', 'id');
    }
    
    /**
     * Get all segments (videos) for this channel.
     */
    public function segments()
    {
        return $this->hasMany(TrueFireSegment::class, 'channel_id', 'id');
    }
}

