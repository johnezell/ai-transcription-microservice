<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocalTruefireChannel extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'local_truefire_channels';

    // Uses default SQLite connection

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'xml_filename',
        'title',
        'posterframe',
        'adimage',
        'adlink',
        'thumbnails',
        'guide',
        'emailredirect',
        'prerollchance',
        'prerollgroup',
        'postroll',
        'bannerform',
        'description',
        'menuImage',
        'video',
        'commercial',
        'more',
        'adimage2',
        'adlink2',
        'foldername',
        'version',
        'style',
        'curriculum',
        'level1',
        'level',
        'inlinechance',
        'inlinegroup',
        'bandwidthHi',
        'bandwidthMed',
        'run_time',
        'new_item',
        'on_sale',
        'top_picks',
        'tf_itemid',
        'tf_thumb',
        'tf_authorid',
        'tf_thumb2',
        'educator_name',
        'educator_url',
        'video_prefix',
        'courseid',
        'date_modified',
        'add_fields',
        'ch_extra_assets',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'thumbnails' => 'string',
        'style' => 'array',
        'curriculum' => 'array',
        'level' => 'array',
        'level1' => 'integer',
        'new_item' => 'boolean',
        'on_sale' => 'boolean',
        'top_picks' => 'boolean',
        'tf_itemid' => 'integer',
        'tf_authorid' => 'integer',
        'courseid' => 'integer',
        'run_time' => 'datetime:H:i:s',
        'date_modified' => 'datetime',
    ];

    /**
     * Get the course that owns the channel.
     */
    public function course()
    {
        return $this->belongsTo(LocalTruefireCourse::class, 'courseid', 'id');
    }

    /**
     * Get the segments for the channel.
     */
    public function segments()
    {
        return $this->hasMany(LocalTruefireSegment::class, 'channel_id', 'id');
    }

    /**
     * Get segments with valid video fields for the channel.
     */
    public function segmentsWithVideo()
    {
        return $this->hasMany(LocalTruefireSegment::class, 'channel_id', 'id')
                    ->withVideo();
    }

    /**
     * Get the name/title for display purposes.
     * Prioritizes 'title' field, falls back to 'xml_filename'
     */
    public function getDisplayNameAttribute()
    {
        return $this->title ?: $this->xml_filename;
    }
}