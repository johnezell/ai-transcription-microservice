<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TranscriptionLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'job_id',
        'video_id',
        'status',
        'request_data',
        'response_data',
        'error_message',
        'started_at',
        'completed_at',
        'audio_extraction_started_at',
        'audio_extraction_completed_at',
        'transcription_started_at',
        'transcription_completed_at',
        'music_term_recognition_started_at',
        'music_term_recognition_completed_at',
        'audio_extraction_duration_seconds',
        'transcription_duration_seconds',
        'music_term_recognition_duration_seconds',
        'total_processing_duration_seconds',
        'audio_file_size',
        'audio_duration_seconds',
        'progress_percentage',
        'music_term_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'audio_extraction_started_at' => 'datetime',
        'audio_extraction_completed_at' => 'datetime',
        'transcription_started_at' => 'datetime',
        'transcription_completed_at' => 'datetime',
        'music_term_recognition_started_at' => 'datetime',
        'music_term_recognition_completed_at' => 'datetime',
        'audio_extraction_duration_seconds' => 'float',
        'transcription_duration_seconds' => 'float',
        'music_term_recognition_duration_seconds' => 'float',
        'total_processing_duration_seconds' => 'float',
        'audio_file_size' => 'integer',
        'audio_duration_seconds' => 'float',
        'progress_percentage' => 'integer',
        'music_term_count' => 'integer',
    ];
    
    /**
     * Get the video that this transcription log belongs to.
     */
    public function video()
    {
        return $this->belongsTo(Video::class);
    }
}
