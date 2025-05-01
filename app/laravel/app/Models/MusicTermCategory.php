<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MusicTermCategory extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'color_class',
        'active',
        'display_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'boolean',
        'display_order' => 'integer',
    ];

    /**
     * Get the terms for the category.
     */
    public function terms(): HasMany
    {
        return $this->hasMany(MusicTerm::class, 'category_id');
    }

    /**
     * Get only active terms for the category.
     */
    public function activeTerms(): HasMany
    {
        return $this->hasMany(MusicTerm::class, 'category_id')
            ->where('active', true);
    }
}
