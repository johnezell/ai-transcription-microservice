<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class AudioTestBatch extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'truefire_course_id',
        'name',
        'description',
        'quality_level',
        'extraction_settings',
        'segment_ids',
        'total_segments',
        'completed_segments',
        'failed_segments',
        'status',
        'started_at',
        'completed_at',
        'estimated_duration',
        'actual_duration',
        'concurrent_jobs',
        'batch_job_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'extraction_settings' => 'array',
        'segment_ids' => 'array',
        'total_segments' => 'integer',
        'completed_segments' => 'integer',
        'failed_segments' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'estimated_duration' => 'integer',
        'actual_duration' => 'integer',
        'concurrent_jobs' => 'integer',
    ];

    /**
     * Get the user that owns the batch.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the TrueFire course associated with the batch.
     */
    public function truefireCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'truefire_course_id');
    }

    /**
     * Get the transcription logs associated with this batch.
     */
    public function transcriptionLogs(): HasMany
    {
        return $this->hasMany(TranscriptionLog::class, 'audio_test_batch_id');
    }

    /**
     * Get the completed transcription logs for this batch.
     */
    public function completedLogs(): HasMany
    {
        return $this->transcriptionLogs()->where('status', 'completed');
    }

    /**
     * Get the failed transcription logs for this batch.
     */
    public function failedLogs(): HasMany
    {
        return $this->transcriptionLogs()->where('status', 'failed');
    }

    /**
     * Get the processing transcription logs for this batch.
     */
    public function processingLogs(): HasMany
    {
        return $this->transcriptionLogs()->where('status', 'processing');
    }

    /**
     * Calculate the progress percentage of the batch.
     *
     * @return float
     */
    public function getProgressPercentageAttribute(): float
    {
        if ($this->total_segments === 0) {
            return 0.0;
        }

        return round(($this->completed_segments + $this->failed_segments) / $this->total_segments * 100, 2);
    }

    /**
     * Get the remaining segments count.
     *
     * @return int
     */
    public function getRemainingSegmentsAttribute(): int
    {
        return $this->total_segments - $this->completed_segments - $this->failed_segments;
    }

    /**
     * Check if the batch is completed.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the batch is processing.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Check if the batch has failed.
     *
     * @return bool
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if the batch is cancelled.
     *
     * @return bool
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Mark the batch as started.
     *
     * @return void
     */
    public function markAsStarted(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark the batch as completed.
     *
     * @return void
     */
    public function markAsCompleted(): void
    {
        $completedAt = now();
        $actualDuration = $this->started_at ? $completedAt->diffInSeconds($this->started_at) : null;

        $this->update([
            'status' => 'completed',
            'completed_at' => $completedAt,
            'actual_duration' => $actualDuration,
        ]);
    }

    /**
     * Mark the batch as failed.
     *
     * @return void
     */
    public function markAsFailed(): void
    {
        $completedAt = now();
        $actualDuration = $this->started_at ? $completedAt->diffInSeconds($this->started_at) : null;

        $this->update([
            'status' => 'failed',
            'completed_at' => $completedAt,
            'actual_duration' => $actualDuration,
        ]);
    }

    /**
     * Mark the batch as cancelled.
     *
     * @return void
     */
    public function markAsCancelled(): void
    {
        $completedAt = now();
        $actualDuration = $this->started_at ? $completedAt->diffInSeconds($this->started_at) : null;

        $this->update([
            'status' => 'cancelled',
            'completed_at' => $completedAt,
            'actual_duration' => $actualDuration,
        ]);
    }

    /**
     * Update the batch progress counters.
     *
     * @return void
     */
    public function updateProgress(): void
    {
        $completed = $this->transcriptionLogs()->where('status', 'completed')->count();
        $failed = $this->transcriptionLogs()->where('status', 'failed')->count();

        $this->update([
            'completed_segments' => $completed,
            'failed_segments' => $failed,
        ]);

        // Check if batch is complete
        if (($completed + $failed) >= $this->total_segments && $this->status === 'processing') {
            $this->markAsCompleted();
        }
    }

    /**
     * Estimate the duration for processing this batch.
     *
     * @param int $averageSegmentDuration Average processing time per segment in seconds
     * @return int Estimated duration in seconds
     */
    public function estimateDuration(int $averageSegmentDuration = 30): int
    {
        if ($this->total_segments === 0) {
            return 0;
        }

        // Calculate based on concurrent jobs
        $concurrentJobs = max(1, $this->concurrent_jobs);
        $estimatedDuration = ceil($this->total_segments / $concurrentJobs) * $averageSegmentDuration;

        $this->update(['estimated_duration' => $estimatedDuration]);

        return $estimatedDuration;
    }

    /**
     * Get the estimated time remaining for the batch.
     *
     * @return int|null Estimated remaining time in seconds
     */
    public function getEstimatedTimeRemainingAttribute(): ?int
    {
        if (!$this->isProcessing() || !$this->started_at || $this->total_segments === 0) {
            return null;
        }

        $elapsedTime = now()->diffInSeconds($this->started_at);
        $processedSegments = $this->completed_segments + $this->failed_segments;

        if ($processedSegments === 0) {
            return $this->estimated_duration;
        }

        $averageTimePerSegment = $elapsedTime / $processedSegments;
        $remainingSegments = $this->total_segments - $processedSegments;

        return (int) ceil($remainingSegments * $averageTimePerSegment);
    }

    /**
     * Scope to filter batches by status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter batches by user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter batches by course.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $courseId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCourse($query, int $courseId)
    {
        return $query->where('truefire_course_id', $courseId);
    }

    /**
     * Scope to get recent batches.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
