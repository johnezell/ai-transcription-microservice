<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Video extends Model
{
    use HasFactory, HasUuid;
    
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
        'audio_path',
        'audio_duration',
        'audio_size',
        'transcript_path',
        'transcript_text',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'size_bytes' => 'integer',
        'audio_size' => 'integer',
        'audio_duration' => 'float',
    ];
    
    /**
     * Get the URL for the video.
     *
     * @return string
     */
    public function getUrlAttribute()
    {
        if (empty($this->storage_path)) {
            return null;
        }
        
        return asset('storage/' . $this->storage_path);
    }
    
    /**
     * Get the URL for the audio file if it exists.
     * 
     * @return string|null
     */
    public function getAudioUrlAttribute()
    {
        if (empty($this->audio_path)) {
            return null;
        }
        
        // If it's a relative path within the public disk
        if (str_starts_with($this->audio_path, 's3/')) {
            return asset('storage/' . $this->audio_path);
        }
        
        // Convert absolute path to relative URL
        if (str_starts_with($this->audio_path, '/var/www/storage/app/public/')) {
            $path = str_replace('/var/www/storage/app/public/', '', $this->audio_path);
            return asset('storage/' . $path);
        }
        
        // Default fallback - just return the path as-is
        return $this->audio_path;
    }
    
    /**
     * Get the URL for the transcript file if it exists.
     * 
     * @return string|null
     */
    public function getTranscriptUrlAttribute()
    {
        if (empty($this->transcript_path)) {
            return null;
        }
        
        // If it's a relative path within the public disk
        if (str_starts_with($this->transcript_path, 's3/')) {
            return asset('storage/' . $this->transcript_path);
        }
        
        // Convert absolute path to relative URL
        if (str_starts_with($this->transcript_path, '/var/www/storage/app/public/')) {
            $path = str_replace('/var/www/storage/app/public/', '', $this->transcript_path);
            return asset('storage/' . $path);
        }
        
        // Default fallback - just return the path as-is
        return $this->transcript_path;
    }
    
    /**
     * Format the audio duration as a readable string.
     * 
     * @return string|null
     */
    public function getFormattedDurationAttribute()
    {
        if (!$this->audio_duration) {
            return null;
        }
        
        $minutes = floor($this->audio_duration / 60);
        $seconds = $this->audio_duration % 60;
        
        return sprintf('%02d:%02d', $minutes, $seconds);
    }
    
    /**
     * Check if the video is in a processing state.
     * 
     * @return bool
     */
    public function getIsProcessingAttribute()
    {
        return in_array($this->status, ['processing', 'extracting_audio', 'transcribing']);
    }
    
    /**
     * Get transcription log associated with this video.
     */
    public function transcriptionLog()
    {
        return $this->hasOne(TranscriptionLog::class);
    }
}
