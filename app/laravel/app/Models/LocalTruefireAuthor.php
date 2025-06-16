<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocalTruefireAuthor extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'local_truefire_authors';

    // Uses default SQLite connection

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'authorid',
        'authorfirstname',
        'authorlastname',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'authorid' => 'integer',
    ];

    /**
     * Get the courses that belong to this author.
     */
    public function courses()
    {
        return $this->hasMany(LocalTruefireCourse::class, 'authorid', 'authorid');
    }

    /**
     * Get the author's full name.
     *
     * @return string
     */
    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            $this->authorfirstname,
            $this->authorlastname
        ]);
        
        return implode(' ', $parts);
    }

    /**
     * Get the author's display name for UI purposes.
     *
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->full_name ?: "Author #{$this->authorid}";
    }
}
