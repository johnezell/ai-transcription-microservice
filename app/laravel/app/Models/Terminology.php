<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Terminology extends Model // Renamed class
{
    use HasFactory;

    protected $table = 'terminologies'; // Explicit table name

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'category_id',
        'term',
        'description',
        'active',
        'patterns', // Added new field
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'boolean',
        'patterns' => 'json', // Cast new field to json
    ];

    /**
     * Get the category that owns the term.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TerminologyCategory::class, 'category_id'); // Updated related model
    }
} 