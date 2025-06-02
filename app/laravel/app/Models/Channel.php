<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    use HasFactory;

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
    protected $table = 'channels.channels';

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
     * Get the course that owns the channel.
     */
    public function course()
    {
        return $this->belongsTo(TruefireCourse::class, 'courseid', 'id');
    }

    /**
     * Get the segments for the channel.
     */
    public function segments()
    {
        return $this->hasMany(Segment::class, 'channel_id', 'id');
    }
}