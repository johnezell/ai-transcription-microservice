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
        'audio_extraction_approved',
        'audio_extraction_approved_at',
        'audio_extraction_approved_by',
        'audio_extraction_notes',
        'transcript_path',
        'transcript_text',
        'transcript_json',
        'transcript_srt',
        'terminology_path',
        'terminology_count',
        'terminology_metadata',
        'terminology_json',
        'has_terminology',
        'course_id',
        'lesson_number',
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
        'audio_extraction_approved' => 'boolean',
        'audio_extraction_approved_at' => 'datetime',
        'terminology_count' => 'integer',
        'terminology_metadata' => 'array',
        'transcript_json' => 'array',
        'terminology_json' => 'array',
        'has_terminology' => 'boolean',
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
     * Get the URL for the terminology JSON file if it exists.
     * 
     * @return string|null
     */
    public function getTerminologyUrlAttribute()
    {
        if (empty($this->terminology_path)) {
            return null;
        }
        
        // If it's a relative path within the public disk
        if (str_starts_with($this->terminology_path, 's3/')) {
            return asset('storage/' . $this->terminology_path);
        }
        
        // Convert absolute path to relative URL
        if (str_starts_with($this->terminology_path, '/var/www/storage/app/public/')) {
            $path = str_replace('/var/www/storage/app/public/', '', $this->terminology_path);
            return asset('storage/' . $path);
        }
        
        // Default fallback - just return the path as-is
        return $this->terminology_path;
    }
    
    /**
     * Get the URL for the music terms JSON file if it exists.
     * 
     * @return string|null
     * @deprecated Use getTerminologyUrlAttribute instead
     */
    public function getMusicTermsUrlAttribute()
    {
        return $this->getTerminologyUrlAttribute();
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
        return in_array($this->status, [
            'processing', 
            'extracting_audio',
            'audio_extracted', 
            'transcribing', 
            'transcribed',
            'processing_music_terms'
        ]);
    }
    
    /**
     * Check if the video is ready for audio extraction approval.
     * 
     * @return bool
     */
    public function getIsReadyForAudioApprovalAttribute()
    {
        return $this->status === 'audio_extracted' && 
               !empty($this->audio_path) && 
               !$this->audio_extraction_approved;
    }
    
    /**
     * Check if the audio extraction has been approved.
     * 
     * @return bool
     */
    public function getIsAudioApprovedAttribute()
    {
        return $this->audio_extraction_approved === true;
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
     * Get the transcript JSON data.
     *
     * @return array|null
     */
    public function getTranscriptJsonDataAttribute()
    {
        // If we have JSON data stored in the database, return it
        if (!empty($this->transcript_json)) {
            return $this->transcript_json;
        }
        
        // Otherwise try to load from file if we have a transcript path
        if (empty($this->transcript_path)) {
            return null;
        }
        
        // Get the directory path from the transcript path
        $dir = dirname($this->transcript_path);
        $jsonPath = $dir . '/transcript.json';
        
        // If the JSON file exists, read and return its contents
        if (file_exists($jsonPath)) {
            try {
                $jsonData = json_decode(file_get_contents($jsonPath), true);
                return $jsonData;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to read transcript JSON file: ' . $e->getMessage());
                return null;
            }
        }
        
        return null;
    }

    /**
     * Get the music terms JSON data.
     *
     * @return array|null
     * @deprecated Use getTerminologyJsonDataAttribute instead
     */
    public function getMusicTermsJsonDataAttribute()
    {
        return $this->getTerminologyJsonDataAttribute();
    }
    
    /**
     * Check if music terms are available for this video.
     * 
     * @return bool
     * @deprecated Use getHasTerminologyAttribute instead
     */
    public function getHasMusicTermsAttribute()
    {
        return $this->getHasTerminologyAttribute();
    }

    /**
     * Get the terminology JSON data.
     *
     * @return array|null
     */
    public function getTerminologyJsonDataAttribute()
    {
        // If we have JSON data stored in the database, return it
        if (!empty($this->terminology_json)) {
            return $this->terminology_json;
        }
        
        // Otherwise try to load from file if we have a terminology path
        if (empty($this->terminology_path)) {
            return null;
        }
        
        // If the JSON file exists, read and return its contents
        if (file_exists($this->terminology_path)) {
            try {
                $jsonData = json_decode(file_get_contents($this->terminology_path), true);
                return $jsonData;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to read terminology JSON file: ' . $e->getMessage());
                return null;
            }
        }
        
        return null;
    }
    
    /**
     * Check if terminology is available for this video.
     * 
     * @return bool
     */
    public function getHasTerminologyAttribute()
    {
        return !empty($this->terminology_json) ||
               !empty($this->terminology_path) || 
               !empty($this->terminology_metadata) || 
               ($this->terminology_count ?? 0) > 0;
    }

    /**
     * Get the URL for the transcript JSON file if it exists.
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

    /**
     * Get the URL for accessing transcript JSON data from the database API.
     *
     * @return string|null
     */
    public function getTranscriptJsonApiUrlAttribute()
    {
        if (!$this->id) {
            return null;
        }
        
        return url('/api/videos/' . $this->id . '/transcript-json');
    }

    /**
     * Get the URL for accessing terminology JSON data from the database API.
     *
     * @return string|null
     */
    public function getTerminologyJsonApiUrlAttribute()
    {
        if (!$this->id) {
            return null;
        }
        
        return url('/api/videos/' . $this->id . '/terminology-json');
    }

    /**
     * Get the course this video belongs to.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }
    
    /**
     * Get the lesson title, which combines the lesson number with original filename.
     */
    public function getLessonTitleAttribute()
    {
        if ($this->course_id && $this->lesson_number) {
            return "Lesson {$this->lesson_number}: {$this->original_filename}";
        }
        
        return $this->original_filename;
    }

    /**
     * Get the next video in the course sequence, if any.
     */
    public function getNextLessonAttribute()
    {
        if (!$this->course_id) {
            return null;
        }
        
        return Video::where('course_id', $this->course_id)
            ->where('lesson_number', '>', $this->lesson_number)
            ->orderBy('lesson_number', 'asc')
            ->first();
    }
    
    /**
     * Get the previous video in the course sequence, if any.
     */
    public function getPreviousLessonAttribute()
    {
        if (!$this->course_id) {
            return null;
        }
        
        return Video::where('course_id', $this->course_id)
            ->where('lesson_number', '<', $this->lesson_number)
            ->orderBy('lesson_number', 'desc')
            ->first();
    }
}
