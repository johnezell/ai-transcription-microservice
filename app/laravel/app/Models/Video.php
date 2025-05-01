<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    use HasFactory;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'original_filename',
        'storage_path',
        's3_key',
        'mime_type',
        'size_bytes',
        'status',
        'metadata',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'size_bytes' => 'integer',
    ];
    
    /**
     * Get the URL for the video.
     *
     * @return string
     */
    public function getUrlAttribute()
    {
        return asset('storage/' . str_replace('public/', '', $this->storage_path));
    }
    
    /**
     * Get transcription log associated with this video.
     */
    public function transcriptionLog()
    {
        return $this->hasOne(TranscriptionLog::class);
    }
}
