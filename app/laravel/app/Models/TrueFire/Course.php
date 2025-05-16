<?php

namespace App\Models\TrueFire;

use App\Models\Channels\Channel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
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
    protected $table = 'courses'; // Adjust if your table has a different name

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'description',
        'instructor',
        'difficulty_level',
        'category',
        'published_at',
        'duration_minutes',
        'thumbnail_url',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'published_at' => 'datetime',
        'duration_minutes' => 'integer',
    ];

    /**
     * Get the lessons for this course.
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /**
     * Get the channels for this course.
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class, 'courseid');
    }
} 