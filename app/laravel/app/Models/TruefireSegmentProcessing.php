<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class TruefireSegmentProcessing extends Model
{
    use HasFactory;

    protected $table = 'truefire_segment_processing';

    protected $fillable = [
        'segment_id',
        'course_id',
        'status',
        'progress_percentage',
        'error_message',
        'priority',
        
        // Audio extraction
        'audio_path',
        'audio_size',
        'audio_duration',
        'has_audio',
        'audio_extraction_approved',
        'audio_extraction_approved_at',
        'audio_extraction_approved_by',
        'audio_extraction_notes',
        
        // Transcription
        'transcript_path',
        'transcript_text',
        'transcript_json',
        
        // Terminology
        'has_terminology',
        'terminology_path',
        'terminology_json',
        'terminology_count',
        'terminology_metadata',
        
        // Processing timestamps
        'audio_extraction_started_at',
        'audio_extraction_completed_at',
        'transcription_started_at',
        'transcription_completed_at',
        'terminology_started_at',
        'terminology_completed_at',
        'completed_at',
    ];

    protected $casts = [
        'segment_id' => 'integer',
        'course_id' => 'integer',
        'progress_percentage' => 'integer',
        'priority' => 'string',
        'audio_size' => 'integer',
        'audio_duration' => 'float',
        'has_audio' => 'boolean',
        'audio_extraction_approved' => 'boolean',
        'has_terminology' => 'boolean',
        'terminology_count' => 'integer',
        'transcript_json' => 'json',
        'terminology_json' => 'json',
        'terminology_metadata' => 'json',
        'audio_extraction_approved_at' => 'datetime',
        'audio_extraction_started_at' => 'datetime',
        'audio_extraction_completed_at' => 'datetime',
        'transcription_started_at' => 'datetime',
        'transcription_completed_at' => 'datetime',
        'terminology_started_at' => 'datetime',
        'terminology_completed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the segment that owns this processing record.
     */
    public function segment()
    {
        return $this->belongsTo(LocalTruefireSegment::class, 'segment_id');
    }

    /**
     * Get the course that owns this processing record.
     */
    public function course()
    {
        return $this->belongsTo(LocalTruefireCourse::class, 'course_id');
    }

    /**
     * Check if the segment is currently being processed.
     */
    public function getIsProcessingAttribute()
    {
        return in_array($this->status, ['processing', 'transcribing', 'processing_terminology']);
    }

    /**
     * Get the audio URL if the audio file exists.
     */
    public function getAudioUrlAttribute()
    {
        if (empty($this->audio_path) || !Storage::exists($this->audio_path)) {
            return null;
        }
        
        return Storage::url($this->audio_path);
    }

    /**
     * Get the transcript URL if the transcript file exists.
     */
    public function getTranscriptUrlAttribute()
    {
        if (empty($this->transcript_path) || !Storage::exists($this->transcript_path)) {
            return null;
        }
        
        return Storage::url($this->transcript_path);
    }

    /**
     * Get the subtitles URL (SRT format) if it exists.
     */
    public function getSubtitlesUrlAttribute()
    {
        if (empty($this->transcript_path)) {
            return null;
        }
        
        $srtPath = str_replace('.txt', '.srt', $this->transcript_path);
        
        if (!Storage::exists($srtPath)) {
            return null;
        }
        
        return Storage::url($srtPath);
    }

    /**
     * Get the transcript JSON URL if it exists.
     */
    public function getTranscriptJsonUrlAttribute()
    {
        if (empty($this->transcript_path)) {
            return null;
        }
        
        $dir = dirname($this->transcript_path);
        $jsonPath = $dir . '/transcript.json';
        
        if (!Storage::exists($jsonPath)) {
            return null;
        }
        
        return Storage::url($jsonPath);
    }

    /**
     * Get the terminology URL if the terminology file exists.
     */
    public function getTerminologyUrlAttribute()
    {
        if (empty($this->terminology_path) || !Storage::exists($this->terminology_path)) {
            return null;
        }
        
        return Storage::url($this->terminology_path);
    }

    /**
     * Get formatted duration string.
     */
    public function getFormattedDurationAttribute()
    {
        if (empty($this->audio_duration)) {
            return null;
        }
        
        $minutes = floor($this->audio_duration / 60);
        $seconds = $this->audio_duration % 60;
        
        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Scope to get processing records by status.
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get processing records that are currently processing.
     */
    public function scopeProcessing($query)
    {
        return $query->whereIn('status', ['processing', 'transcribing', 'processing_terminology']);
    }

    /**
     * Scope to get completed processing records.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope to get failed processing records.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Mark the processing as failed with an error message.
     */
    public function markAsFailed($errorMessage)
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'progress_percentage' => 0
        ]);
    }

    /**
     * Mark the processing as completed.
     */
    public function markAsCompleted()
    {
        $this->update([
            'status' => 'completed',
            'progress_percentage' => 100,
            'completed_at' => now()
        ]);
    }

    /**
     * Start audio extraction processing.
     */
    public function startAudioExtraction()
    {
        $updateData = [
            'status' => 'processing',
            'audio_extraction_started_at' => now(),
            'progress_percentage' => 25
        ];
        
        // Set default priority if not already set
        if (empty($this->priority)) {
            $updateData['priority'] = 'normal';
        }
        
        $this->update($updateData);
    }

    /**
     * Complete audio extraction processing.
     */
    public function completeAudioExtraction($audioPath, $audioSize = null, $audioDuration = null)
    {
        $this->update([
            'status' => 'audio_extracted',
            'audio_path' => $audioPath,
            'audio_size' => $audioSize,
            'audio_duration' => $audioDuration,
            'has_audio' => true,
            'audio_extraction_completed_at' => now(),
            'progress_percentage' => 40
        ]);
    }

    /**
     * Start transcription processing.
     */
    public function startTranscription()
    {
        $this->update([
            'status' => 'transcribing',
            'transcription_started_at' => now(),
            'progress_percentage' => 60
        ]);
    }

    /**
     * Complete transcription processing.
     */
    public function completeTranscription($transcriptPath, $transcriptText = null, $transcriptJson = null)
    {
        $this->update([
            'status' => 'transcribed',
            'transcript_path' => $transcriptPath,
            'transcript_text' => $transcriptText,
            'transcript_json' => $transcriptJson,
            'transcription_completed_at' => now(),
            'progress_percentage' => 75
        ]);
    }

    /**
     * Start terminology processing.
     */
    public function startTerminology()
    {
        $this->update([
            'status' => 'processing_terminology',
            'terminology_started_at' => now(),
            'progress_percentage' => 85
        ]);
    }

    /**
     * Complete terminology processing.
     */
    public function completeTerminology($terminologyPath, $terminologyJson = null, $terminologyCount = null, $terminologyMetadata = null)
    {
        $this->update([
            'status' => 'completed',
            'has_terminology' => true,
            'terminology_path' => $terminologyPath,
            'terminology_json' => $terminologyJson,
            'terminology_count' => $terminologyCount,
            'terminology_metadata' => $terminologyMetadata,
            'terminology_completed_at' => now(),
            'completed_at' => now(),
            'progress_percentage' => 100
        ]);
    }

    /**
     * Get queue name for audio extraction jobs (always main queue with single queue + priority system).
     */
    public function getAudioExtractionQueueName()
    {
        return 'audio-extraction';
    }

    /**
     * Get queue name for transcription jobs (always main queue with single queue + priority system).
     */
    public function getTranscriptionQueueName()
    {
        return 'transcription';
    }

    /**
     * Get Laravel job priority value based on priority level.
     */
    public function getJobPriority()
    {
        return match($this->priority) {
            'high' => 10,     // High priority jobs processed first
            'low' => -1,      // Low priority jobs processed last
            default => 0      // Normal priority (default)
        };
    }

    /**
     * Set priority level for this processing record.
     */
    public function setPriority(string $priority)
    {
        $validPriorities = ['high', 'normal', 'low'];
        
        if (in_array($priority, $validPriorities)) {
            $this->update(['priority' => $priority]);
        } else {
            throw new \InvalidArgumentException("Invalid priority: {$priority}. Must be one of: " . implode(', ', $validPriorities));
        }
    }
} 