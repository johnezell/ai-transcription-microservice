<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Segment extends Model
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
    protected $table = 'channels.segments';

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
     * Get the channel that owns the segment.
     */
    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id', 'id');
    }

    /**
     * Get the course through the channel relationship.
     */
    public function course()
    {
        return $this->hasOneThrough(
            TruefireCourse::class,
            Channel::class,
            'id',        // Foreign key on channels table
            'id',        // Foreign key on courses table
            'channel_id', // Local key on segments table
            'courseid'   // Local key on channels table
        );
    }

    public function getSignedUrl()
    {
        $cloudfront_url = "https://d3ldx91n93axbt.cloudfront.net/";
        $video = str_replace('mp4:', '', $this->video) . '_med.mp4';
        $url = $cloudfront_url . $video;
        
        $cloudfrontService = app(\App\Services\CloudFrontSigningService::class);
        return $cloudfrontService->signUrl($url);
    }
    
}