<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Article extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'content',
        'author',
        'meta_description',
        'slug',
        'source_type',
        'source_url',
        'source_file',
        'transcript',
        'video_id',
        'status',
        'error_message',
        'brand_id',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the video that this article was generated from (if any)
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    /**
     * Get all comments for this article
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ArticleComment::class)->whereNull('parent_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get all comments including replies
     */
    public function allComments(): HasMany
    {
        return $this->hasMany(ArticleComment::class)->orderBy('created_at', 'desc');
    }

    /**
     * Check if article is currently being generated
     */
    public function isGenerating(): bool
    {
        return $this->status === 'generating';
    }

    /**
     * Check if article is published
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Scope to filter by brand
     */
    public function scopeForBrand($query, string $brandId)
    {
        return $query->where('brand_id', $brandId);
    }

    /**
     * Scope to get only published articles
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Scope to exclude generating/error articles
     */
    public function scopeReady($query)
    {
        return $query->whereIn('status', ['draft', 'published', 'archived']);
    }

    /**
     * Generate a unique slug from the title
     */
    public static function generateSlug(string $title, ?int $excludeId = null): string
    {
        $slug = \Illuminate\Support\Str::slug($title);
        $originalSlug = $slug;
        $counter = 1;

        $query = static::where('slug', $slug);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        while ($query->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
            
            $query = static::where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
        }

        return $slug;
    }
}


