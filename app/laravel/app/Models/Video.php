<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
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
        'transcript_json',
        'transcript_srt',
        'terminology_path',
        'terminology_count',
        'terminology_metadata',
        'terminology_json',
        'has_terminology',
        'course_id',
        'lesson_number',
        'music_terms_path',
        'music_terms_count',
        'music_terms_metadata',
        'has_music_terms',
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
        'terminology_count' => 'integer',
        'terminology_metadata' => 'array',
        'transcript_json' => 'array',
        'terminology_json' => 'array',
        'has_terminology' => 'boolean',
        'has_music_terms' => 'boolean',
    ];
    
    /**
     * Get the URL for the video.
     * For S3 stored files, this will be a temporary pre-signed URL.
     *
     * @return string|null
     */
    public function getUrlAttribute(): ?string
    {
        if (empty($this->storage_path)) {
            Log::debug('[Video Model] getUrlAttribute: storage_path is empty.', ['video_id' => $this->id]);
            return null;
        }

        if (Storage::disk('s3')->exists($this->storage_path)) {
            try {
                // Generate a temporary pre-signed URL valid for 15 minutes
                $s3Url = Storage::disk('s3')->temporaryUrl($this->storage_path, now()->addMinutes(15));
                Log::info('[Video Model] getUrlAttribute: Generated S3 temporary URL.', [
                    'video_id' => $this->id,
                    's3_key' => $this->storage_path,
                    's3_exists' => true,
                    // 'generated_url' => $s3Url // Consider if logging pre-signed URLs is a security concern for production
                ]);
                return $s3Url;
            } catch (\Exception $e) {
                Log::error('[Video Model] getUrlAttribute: Error generating S3 temporary URL.', [
                    'video_id' => $this->id,
                    's3_key' => $this->storage_path,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        } else {
            Log::warning('[Video Model] getUrlAttribute: S3 key does not exist on s3 disk.', [
                'video_id' => $this->id,
                's3_key' => $this->storage_path,
                's3_exists' => false
            ]);
            return null; // Or a placeholder URL indicating the file is missing
        }
    }
    
    /**
     * Get the URL for the audio file if it exists.
     * For S3 stored files, this will be a temporary pre-signed URL.
     * 
     * @return string|null
     */
    public function getAudioUrlAttribute(): ?string
    {
        if (empty($this->audio_path)) {
            Log::debug('[Video Model] getAudioUrlAttribute: audio_path is empty.', ['video_id' => $this->id]);
            return null;
        }

        if (Storage::disk('s3')->exists($this->audio_path)) {
            try {
                $s3Url = Storage::disk('s3')->temporaryUrl($this->audio_path, now()->addMinutes(15));
                Log::info('[Video Model] getAudioUrlAttribute: Generated S3 temporary URL for audio.', [
                    'video_id' => $this->id,
                    's3_key_audio' => $this->audio_path,
                    's3_exists' => true,
                    // 'generated_url' => $s3Url
                ]);
                return $s3Url;
            } catch (\Exception $e) {
                Log::error('[Video Model] getAudioUrlAttribute: Error generating S3 temporary URL for audio.', [
                    'video_id' => $this->id,
                    's3_key_audio' => $this->audio_path,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        } else {
            Log::warning('[Video Model] getAudioUrlAttribute: S3 key for audio does not exist on s3 disk.', [
                'video_id' => $this->id,
                's3_key_audio' => $this->audio_path,
                's3_exists' => false
            ]);
            return null;
        }
    }
    
    /**
     * Get the URL for the transcript file if it exists.
     * For S3 stored files, this will be a temporary pre-signed URL.
     * 
     * @return string|null
     */
    public function getTranscriptUrlAttribute(): ?string
    {
        if (empty($this->transcript_path)) {
            Log::debug('[Video Model] getTranscriptUrlAttribute: transcript_path is empty.', ['video_id' => $this->id]);
            return null;
        }

        if (Storage::disk('s3')->exists($this->transcript_path)) {
            try {
                $s3Url = Storage::disk('s3')->temporaryUrl($this->transcript_path, now()->addMinutes(15));
                Log::info('[Video Model] getTranscriptUrlAttribute: Generated S3 temporary URL for transcript.', [
                    'video_id' => $this->id,
                    's3_key_transcript' => $this->transcript_path,
                    's3_exists' => true
                ]);
                return $s3Url;
            } catch (\Exception $e) {
                Log::error('[Video Model] getTranscriptUrlAttribute: Error generating S3 temporary URL for transcript.', [
                    'video_id' => $this->id,
                    's3_key_transcript' => $this->transcript_path,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        } else {
            Log::warning('[Video Model] getTranscriptUrlAttribute: S3 key for transcript does not exist on s3 disk.', [
                'video_id' => $this->id,
                's3_key_transcript' => $this->transcript_path,
                's3_exists' => false
            ]);
            return null;
        }
    }
    
    /**
     * Get the URL for the terminology JSON file if it exists.
     * For S3 stored files, this will be a temporary pre-signed URL.
     * 
     * @return string|null
     */
    public function getTerminologyUrlAttribute(): ?string
    {
        if (empty($this->terminology_path)) {
            Log::debug('[Video Model] getTerminologyUrlAttribute: terminology_path is empty.', ['video_id' => $this->id]);
            return null;
        }

        if (Storage::disk('s3')->exists($this->terminology_path)) {
            try {
                $s3Url = Storage::disk('s3')->temporaryUrl($this->terminology_path, now()->addMinutes(15));
                Log::info('[Video Model] getTerminologyUrlAttribute: Generated S3 temporary URL for terminology.', [
                    'video_id' => $this->id,
                    's3_key_terminology' => $this->terminology_path,
                    's3_exists' => true
                ]);
                return $s3Url;
            } catch (\Exception $e) {
                Log::error('[Video Model] getTerminologyUrlAttribute: Error generating S3 temporary URL for terminology.', [
                    'video_id' => $this->id,
                    's3_key_terminology' => $this->terminology_path,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        } else {
            Log::warning('[Video Model] getTerminologyUrlAttribute: S3 key for terminology does not exist on s3 disk.', [
                'video_id' => $this->id,
                's3_key_terminology' => $this->terminology_path,
                's3_exists' => false
            ]);
            return null;
        }
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
            'transcribing', 
            'transcribed',
            'processing_music_terms'
        ]);
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
     * For S3 stored files, this will be a temporary pre-signed URL.
     * 
     * @return string|null
     */
    public function getSubtitlesUrlAttribute(): ?string
    {
        if (empty($this->transcript_path)) {
            // If there's no base transcript_path, we can't derive an SRT path for S3
            Log::debug('[Video Model] getSubtitlesUrlAttribute: transcript_path is empty, cannot derive SRT path.', ['video_id' => $this->id]);
            return null;
        }
        
        // Derive the expected SRT path on S3 from the transcript_path (assuming it's an S3 key)
        $dir = dirname($this->transcript_path);
        $srtPath = $dir . '/transcript.srt'; // e.g., s3/jobs/UUID/transcript.srt

        if (Storage::disk('s3')->exists($srtPath)) {
            try {
                $s3Url = Storage::disk('s3')->temporaryUrl($srtPath, now()->addMinutes(15));
                Log::info('[Video Model] getSubtitlesUrlAttribute: Generated S3 temporary URL for SRT.', [
                    'video_id' => $this->id,
                    's3_key_srt' => $srtPath,
                    's3_exists' => true
                ]);
                return $s3Url;
            } catch (\Exception $e) {
                Log::error('[Video Model] getSubtitlesUrlAttribute: Error generating S3 temporary URL for SRT.', [
                    'video_id' => $this->id,
                    's3_key_srt' => $srtPath,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        } else {
            Log::warning('[Video Model] getSubtitlesUrlAttribute: Derived S3 key for SRT does not exist.', [
                'video_id' => $this->id,
                'derived_srt_path' => $srtPath,
                'base_transcript_path' => $this->transcript_path,
                's3_exists' => false
            ]);
            return null;
        }
    }
    
    /**
     * Get the transcript JSON data.
     * Attempts to load from database first, then from S3 if path exists.
     *
     * @return array|null
     */
    public function getTranscriptJsonDataAttribute(): ?array
    {
        if (!empty($this->transcript_json)) {
            Log::debug('[Video Model] getTranscriptJsonDataAttribute: Returning data from DB column.', ['video_id' => $this->id]);
            return $this->transcript_json;
        }
        
        if (empty($this->transcript_path)) {
            Log::debug('[Video Model] getTranscriptJsonDataAttribute: transcript_path is empty, cannot load from S3.', ['video_id' => $this->id]);
            return null;
        }
        
        $dir = dirname($this->transcript_path);
        $jsonPath = $dir . '/transcript.json'; // e.g., s3/jobs/UUID/transcript.json

        if (Storage::disk('s3')->exists($jsonPath)) {
            try {
                $jsonDataString = Storage::disk('s3')->get($jsonPath);
                if ($jsonDataString === null) {
                    Log::warning('[Video Model] getTranscriptJsonDataAttribute: S3 get() returned null for transcript JSON.', [
                        'video_id' => $this->id,
                        's3_key_json' => $jsonPath
                    ]);
                    return null;
                }
                $decodedData = json_decode($jsonDataString, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('[Video Model] getTranscriptJsonDataAttribute: Failed to decode transcript JSON from S3.', [
                        'video_id' => $this->id,
                        's3_key_json' => $jsonPath,
                        'json_error' => json_last_error_msg()
                    ]);
                    return null;
                }
                Log::info('[Video Model] getTranscriptJsonDataAttribute: Successfully loaded and decoded transcript JSON from S3.', ['video_id' => $this->id, 's3_key_json' => $jsonPath]);
                return $decodedData;
            } catch (\Exception $e) {
                Log::error('[Video Model] getTranscriptJsonDataAttribute: Exception reading transcript JSON from S3.', [
                    'video_id' => $this->id,
                    's3_key_json' => $jsonPath,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        } else {
            Log::warning('[Video Model] getTranscriptJsonDataAttribute: Transcript JSON file key does not exist on S3.', [
                'video_id' => $this->id,
                's3_key_json' => $jsonPath
            ]);
            return null;
        }
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
     * Attempts to load from database first, then from S3 if path exists.
     *
     * @return array|null
     */
    public function getTerminologyJsonDataAttribute(): ?array
    {
        if (!empty($this->terminology_json)) {
            Log::debug('[Video Model] getTerminologyJsonDataAttribute: Returning data from DB column.', ['video_id' => $this->id]);
            return $this->terminology_json;
        }
        
        if (empty($this->terminology_path)) {
            Log::debug('[Video Model] getTerminologyJsonDataAttribute: terminology_path is empty, cannot load from S3.', ['video_id' => $this->id]);
            return null;
        }
        
        // Assuming terminology_path directly points to the JSON file key on S3
        if (Storage::disk('s3')->exists($this->terminology_path)) {
            try {
                $jsonDataString = Storage::disk('s3')->get($this->terminology_path);
                 if ($jsonDataString === null) {
                    Log::warning('[Video Model] getTerminologyJsonDataAttribute: S3 get() returned null for terminology JSON.', [
                        'video_id' => $this->id,
                        's3_key_terminology_json' => $this->terminology_path
                    ]);
                    return null;
                }
                $decodedData = json_decode($jsonDataString, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error('[Video Model] getTerminologyJsonDataAttribute: Failed to decode terminology JSON from S3.', [
                        'video_id' => $this->id,
                        's3_key_terminology_json' => $this->terminology_path,
                        'json_error' => json_last_error_msg()
                    ]);
                    return null;
                }
                Log::info('[Video Model] getTerminologyJsonDataAttribute: Successfully loaded and decoded terminology JSON from S3.', ['video_id' => $this->id, 's3_key_terminology_json' => $this->terminology_path]);
                return $decodedData;
            } catch (\Exception $e) {
                Log::error('[Video Model] getTerminologyJsonDataAttribute: Exception reading terminology JSON from S3.', [
                    'video_id' => $this->id,
                    's3_key_terminology_json' => $this->terminology_path,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        } else {
            Log::warning('[Video Model] getTerminologyJsonDataAttribute: Terminology JSON file key does not exist on S3.', [
                'video_id' => $this->id,
                's3_key_terminology_json' => $this->terminology_path
            ]);
            return null;
        }
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
     * For S3 stored files, this will be a temporary pre-signed URL.
     * 
     * @return string|null
     */
    public function getTranscriptJsonUrlAttribute(): ?string
    {
        Log::critical('[Video Model DEBUG] GETTRANSCRIPTJSONURLATTRIBUTE CALLED', ['video_id' => $this->id, 'transcript_path' => $this->transcript_path]); // VERY LOUD LOG

        if (empty($this->transcript_path)) {
            Log::debug('[Video Model] getTranscriptJsonUrlAttribute: transcript_path is empty, cannot derive JSON path.', ['video_id' => $this->id]);
            return null;
        }
        
        // Derive the expected JSON path on S3 from the transcript_path (e.g., s3/jobs/UUID/transcript.txt -> s3/jobs/UUID/transcript.json)
        $dir = dirname($this->transcript_path);
        $jsonS3Key = $dir . '/transcript.json';

        if (Storage::disk('s3')->exists($jsonS3Key)) {
            try {
                $s3Url = Storage::disk('s3')->temporaryUrl($jsonS3Key, now()->addMinutes(15));
                Log::info('[Video Model] getTranscriptJsonUrlAttribute: Generated S3 temporary URL for JSON.', [
                    'video_id' => $this->id,
                    's3_key_json' => $jsonS3Key,
                    's3_exists' => true
                    // 'generated_url' => $s3Url // Consider security implications of logging pre-signed URLs
                ]);
                return $s3Url;
            } catch (\Exception $e) {
                Log::error('[Video Model] getTranscriptJsonUrlAttribute: Error generating S3 temporary URL for JSON.', [
                    'video_id' => $this->id,
                    's3_key_json' => $jsonS3Key,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        } else {
            Log::warning('[Video Model] getTranscriptJsonUrlAttribute: Derived S3 key for JSON does not exist.', [
                'video_id' => $this->id,
                'derived_json_s3_key' => $jsonS3Key,
                'base_transcript_path' => $this->transcript_path,
                's3_exists' => false
            ]);
            return null;
        }
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

    public function getTerminologyPathAttribute()
    {
        return $this->music_terms_path;
    }

    public function setTerminologyPathAttribute($value)
    {
        $this->attributes['music_terms_path'] = $value;
    }

    public function getTerminologyCountAttribute()
    {
        return $this->music_terms_count;
    }

    public function setTerminologyCountAttribute($value)
    {
        $this->attributes['music_terms_count'] = $value;
    }

    public function getTerminologyMetadataAttribute()
    {
        return $this->music_terms_metadata;
    }

    public function setTerminologyMetadataAttribute($value)
    {
        $this->attributes['music_terms_metadata'] = $value;
    }

    public function setHasTerminologyAttribute($value)
    {
        $this->attributes['has_music_terms'] = $value;
    }
}
