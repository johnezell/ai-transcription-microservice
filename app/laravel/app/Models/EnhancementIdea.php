<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnhancementIdea extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'description',
        'user_id',
        'author_name',
        'completed',
        'completed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the user who created this enhancement idea.
     * May be null for anonymous submissions.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the display name for the author (user name or anonymous name).
     */
    public function getAuthorNameAttribute()
    {
        if ($this->user) {
            return $this->user->name;
        }
        
        return $this->attributes['author_name'] ?? 'Anonymous';
    }

    /**
     * Get the comments for this enhancement idea.
     */
    public function comments()
    {
        return $this->hasMany(EnhancementComment::class);
    }

    /**
     * Get only the root comments (not replies).
     */
    public function rootComments()
    {
        return $this->hasMany(EnhancementComment::class)->whereNull('parent_id');
    }

    /**
     * Mark an enhancement idea as completed.
     */
    public function markAsCompleted()
    {
        $this->update([
            'completed' => true,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark an enhancement idea as not completed.
     */
    public function markAsNotCompleted()
    {
        $this->update([
            'completed' => false,
            'completed_at' => null,
        ]);
    }
} 