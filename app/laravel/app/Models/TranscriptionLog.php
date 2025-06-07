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
        'is_test_extraction',
        'test_quality_level',
        'audio_quality_metrics',
        'extraction_settings',
        'audio_test_batch_id',
        'batch_position',
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
        'is_test_extraction' => 'boolean',
        'audio_quality_metrics' => 'array',
        'extraction_settings' => 'array',
        'batch_position' => 'integer',
    ];
    
    /**
     * Get the video that this transcription log belongs to.
     */
    public function video()
    {
        return $this->belongsTo(Video::class);
    }

    /**
     * Get the audio test batch that this log belongs to.
     */
    public function audioTestBatch()
    {
        return $this->belongsTo(AudioTestBatch::class);
    }

    /**
     * Scope to filter logs by batch.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $batchId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForBatch($query, int $batchId)
    {
        return $query->where('audio_test_batch_id', $batchId);
    }

    /**
     * Scope to filter test extraction logs.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTestExtractions($query)
    {
        return $query->where('is_test_extraction', true);
    }

    /**
     * Scope to filter batch test logs.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBatchTests($query)
    {
        return $query->whereNotNull('audio_test_batch_id');
    }

    /**
     * Check if this log is part of a batch test.
     *
     * @return bool
     */
    public function isBatchTest(): bool
    {
        return !is_null($this->audio_test_batch_id);
    }

    /**
     * Get the processing time in seconds.
     *
     * @return float|null
     */
    public function getProcessingTimeAttribute(): ?float
    {
        return $this->total_processing_duration_seconds;
    }
}
