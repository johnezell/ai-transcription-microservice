<?php

namespace App\Models\TrueFire;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
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
    protected $table = 'lessons'; // Adjust if your table has a different name

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'course_id',
        'title',
        'description',
        'sequence_number',
        'duration_minutes',
        'video_url',
        'thumbnail_url',
        'is_free_preview',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'sequence_number' => 'integer',
        'duration_minutes' => 'integer',
        'is_free_preview' => 'boolean',
    ];

    /**
     * Get the course that owns the lesson.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
} 