<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class SegmentDownload extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'segment_id',
        'course_id',
        'status',
        'queued_at',
        'started_at',
        'completed_at',
        'error_message',
        'attempts',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /**
     * Status constants
     */
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Get all available statuses
     *
     * @return array<string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_QUEUED,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ];
    }

    /**
     * Scope a query to only include segments with a specific status.
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
     * Scope a query to only include segments for a specific course.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $courseId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCourse($query, string $courseId)
    {
        return $query->where('course_id', $courseId);
    }

    /**
     * Scope a query to only include stale processing records.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $hoursOld
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeStaleProcessing($query, int $hoursOld = 2)
    {
        return $query->where('status', self::STATUS_PROCESSING)
                    ->where('started_at', '<', Carbon::now()->subHours($hoursOld));
    }

    /**
     * Check if the download is currently in progress.
     *
     * @return bool
     */
    public function isInProgress(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING]);
    }

    /**
     * Check if the download is completed.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the download has failed.
     *
     * @return bool
     */
    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Mark the download as started.
     *
     * @return bool
     */
    public function markAsStarted(): bool
    {
        return $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => Carbon::now(),
            'attempts' => $this->attempts + 1,
        ]);
    }

    /**
     * Mark the download as completed.
     *
     * @return bool
     */
    public function markAsCompleted(): bool
    {
        return $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => Carbon::now(),
            'error_message' => null,
        ]);
    }

    /**
     * Mark the download as failed.
     *
     * @param string $errorMessage
     * @return bool
     */
    public function markAsFailed(string $errorMessage): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => Carbon::now(),
        ]);
    }

    /**
     * Get the segment relationship (if needed for future use).
     * Note: This would require the segment to be in the same database,
     * which may not be the case with the truefire connection.
     */
    public function segment()
    {
        // This relationship might not work due to different database connections
        // Keep it for potential future use if segments are moved to the same DB
        return $this->belongsTo(Segment::class, 'segment_id', 'id');
    }

    /**
     * Create or update a segment download record.
     *
     * @param string $segmentId
     * @param string|null $courseId
     * @param string $status
     * @return static
     */
    public static function createOrUpdate(string $segmentId, ?string $courseId = null, string $status = self::STATUS_QUEUED): self
    {
        return static::updateOrCreate(
            ['segment_id' => $segmentId],
            [
                'course_id' => $courseId,
                'status' => $status,
                'queued_at' => $status === self::STATUS_QUEUED ? Carbon::now() : null,
                'started_at' => $status === self::STATUS_PROCESSING ? Carbon::now() : null,
                'completed_at' => $status === self::STATUS_COMPLETED ? Carbon::now() : null,
                'attempts' => $status === self::STATUS_PROCESSING ? 1 : 0,
            ]
        );
    }

    /**
     * Check if a segment is already being processed or completed.
     *
     * @param string $segmentId
     * @return bool
     */
    public static function isAlreadyProcessed(string $segmentId): bool
    {
        return static::where('segment_id', $segmentId)
                    ->whereIn('status', [self::STATUS_PROCESSING, self::STATUS_COMPLETED])
                    ->exists();
    }

    /**
     * Clean up stale processing records.
     *
     * @param int $hoursOld
     * @return int Number of records cleaned up
     */
    public static function cleanupStaleProcessing(int $hoursOld = 2): int
    {
        return static::staleProcessing($hoursOld)->delete();
    }
}