<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    use HasFactory, HasUuid;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'subject_area',
        'metadata',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
    ];
    
    /**
     * Get the videos associated with this course.
     */
    public function videos()
    {
        return $this->hasMany(Video::class)->orderBy('lesson_number', 'asc');
    }
    
    /**
     * Get the count of videos in this course.
     */
    public function getVideoCountAttribute()
    {
        return $this->videos()->count();
    }
    
    /**
     * Get the total duration of all videos in this course.
     */
    public function getTotalDurationAttribute()
    {
        return $this->videos()->sum('audio_duration');
    }
    
    /**
     * Format the total duration as a readable string.
     */
    public function getFormattedTotalDurationAttribute()
    {
        $totalSeconds = $this->total_duration;
        
        if (!$totalSeconds) {
            return null;
        }
        
        $hours = floor($totalSeconds / 3600);
        $minutes = floor(($totalSeconds % 3600) / 60);
        $seconds = $totalSeconds % 60;
        
        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        } else {
            return sprintf('%02d:%02d', $minutes, $seconds);
        }
    }
} 