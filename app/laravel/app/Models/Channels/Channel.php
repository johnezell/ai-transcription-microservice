<?php

namespace App\Models\Channels;

use App\Models\TrueFire\Course;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    /**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = 'channels';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'channels';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'description',
        'courseid',
        'status',
    ];

    /**
     * Get the course that this channel belongs to.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'courseid');
    }

    /**
     * Get the segments for this channel.
     */
    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class, 'channel_id');
    }
} 