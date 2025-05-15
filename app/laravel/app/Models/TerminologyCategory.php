<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TerminologyCategory extends Model // Renamed class
{
    use HasFactory;

    protected $table = 'terminology_categories'; // Explicit table name

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
        return $this->hasMany(Terminology::class, 'category_id'); // Updated related model
    }

    /**
     * Get only active terms for the category.
     */
    public function activeTerms(): HasMany
    {
        return $this->hasMany(Terminology::class, 'category_id') // Updated related model
            ->where('active', true);
    }
} 