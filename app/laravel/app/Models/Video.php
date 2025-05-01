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
     * @return string|null
     */
    public function getUrlAttribute()
    {
        if (empty($this->storage_path)) {
            return null;
        }
        
        // Debug log to help troubleshoot URL issues
        \Illuminate\Support\Facades\Log::info('Video URL generation', [
            'video_id' => $this->id,
            'storage_path' => $this->storage_path,
            'full_path' => Storage::disk('public')->path($this->storage_path),
            'exists' => Storage::disk('public')->exists($this->storage_path)
        ]);
        
        // If it's a relative path within the public disk (preferred format)
        if (str_starts_with($this->storage_path, 's3/')) {
            return asset('storage/' . $this->storage_path);
        }
        
        // Convert absolute path to relative URL if needed
        if (str_starts_with($this->storage_path, '/var/www/storage/app/public/')) {
            $path = str_replace('/var/www/storage/app/public/', '', $this->storage_path);
            return asset('storage/' . $path);
        }
        
        // Default fallback - assuming it's relative to storage/app/public
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
    
    /**
     * Get the URL for the SRT subtitles file if it exists.
     * 
     * @return string|null
     */
    public function getSubtitlesUrlAttribute()
    {
        if (empty($this->transcript_path)) {
            return null;
        }
        
        // Get the directory path from the transcript path
        $dir = dirname($this->transcript_path);
        $srtPath = $dir . '/transcript.srt';
        
        // Determine the relative path for asset URL
        $relativePath = null;
        
        // If it's inside public disk path
        if (str_starts_with($srtPath, '/var/www/storage/app/public/')) {
            $relativePath = str_replace('/var/www/storage/app/public/', '', $srtPath);
        } else if (str_starts_with($srtPath, 's3/')) {
            $relativePath = $srtPath;
        } else {
            // Try to extract path based on transcript pattern
            if (preg_match('#/s3/jobs/(.+)/transcript\.txt$#', $this->transcript_path, $matches)) {
                $jobId = $matches[1];
                $relativePath = "s3/jobs/{$jobId}/transcript.srt";
            }
        }
        
        // If we have a relative path, return the asset URL
        if ($relativePath) {
            return asset('storage/' . $relativePath);
        }
        
        return null;
    }
    
    /**
     * Get the URL for the transcript.json file if it exists.
     * 
     * @return string|null
     */
    public function getTranscriptJsonUrlAttribute()
    {
        if (empty($this->transcript_path)) {
            return null;
        }
        
        // Get the directory path from the transcript path
        $dir = dirname($this->transcript_path);
        $jsonPath = $dir . '/transcript.json';
        
        // Determine the relative path for asset URL based on the directory pattern
        // This is more reliable than checking if the file exists
        $relativePath = null;
        
        // If it's inside public disk path
        if (str_starts_with($jsonPath, '/var/www/storage/app/public/')) {
            $relativePath = str_replace('/var/www/storage/app/public/', '', $jsonPath);
        } else if (str_starts_with($jsonPath, 's3/')) {
            $relativePath = $jsonPath;
        } else {
            // Try to extract path based on transcript pattern
            if (preg_match('#/s3/jobs/(.+)/transcript\.txt$#', $this->transcript_path, $matches)) {
                $jobId = $matches[1];
                $relativePath = "s3/jobs/{$jobId}/transcript.json";
            }
        }
        
        // If we have a relative path, return the asset URL
        if ($relativePath) {
            return asset('storage/' . $relativePath);
        }
        
        return null;
    }
}
