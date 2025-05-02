<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EnhancementComment extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'enhancement_idea_id',
        'user_id',
        'author_name',
        'parent_id',
        'content'
    ];

    /**
     * Get the enhancement idea that this comment belongs to.
     */
    public function enhancementIdea()
    {
        return $this->belongsTo(EnhancementIdea::class);
    }

    /**
     * Get the user who created this comment.
     * May be null for anonymous comments.
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
     * Get the parent comment if this is a reply.
     */
    public function parent()
    {
        return $this->belongsTo(EnhancementComment::class, 'parent_id');
    }

    /**
     * Get the replies to this comment.
     */
    public function replies()
    {
        return $this->hasMany(EnhancementComment::class, 'parent_id');
    }
} 