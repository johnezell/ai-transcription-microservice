<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleComment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'article_id',
        'user_name',
        'content',
        'selection_text',
        'position_start',
        'position_end',
        'parent_id',
        'thread_id',
    ];

    protected $casts = [
        'position_start' => 'integer',
        'position_end' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the article this comment belongs to
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Get the parent comment (for replies)
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ArticleComment::class, 'parent_id');
    }

    /**
     * Get all replies to this comment
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ArticleComment::class, 'parent_id')->orderBy('created_at', 'asc');
    }

    /**
     * Get the root comment of the thread
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ArticleComment::class, 'thread_id');
    }

    /**
     * Check if this is an inline comment (highlighting specific text)
     */
    public function isInlineComment(): bool
    {
        return !is_null($this->selection_text) && !is_null($this->position_start);
    }

    /**
     * Check if this is a reply
     */
    public function isReply(): bool
    {
        return !is_null($this->parent_id);
    }
}


