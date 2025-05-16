<?php

namespace App\Models\Channels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Segment extends Model
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
    protected $table = 'segments';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'channel_id',
        'title',
        'description',
        'video',
        'duration',
        'sequence',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'duration' => 'integer',
        'sequence' => 'integer',
    ];

    /**
     * Get the channel that this segment belongs to.
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }
} 