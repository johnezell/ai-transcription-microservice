<?php

namespace App\Http\Controllers;

use App\Models\AudioTestBatch;
use App\Models\TranscriptionLog;
use App\Http\Requests\CreateBatchTestRequest;
use App\Jobs\BatchAudioExtractionJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\ValidationException;

class BatchTestController extends Controller
{
    /**
     * Display a listing of the user's batch tests.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = AudioTestBatch::forUser(Auth::id())
            ->with(['truefireCourse', 'transcriptionLogs'])
            ->orderBy('created_at', 'desc');

        // Filter by status if provided
        if ($request->has('status') && $request->status !== 'all') {
            $query->withStatus($request->status);
        }

        // Filter by course if provided
        if ($request->has('course_id')) {
            $query->forCourse($request->course_id);
        }

        // Filter by recent if requested
        if ($request->boolean('recent')) {
            $query->recent($request->get('days', 7));
        }

        $batches = $query->paginate($request->get('per_page', 15));

        // Add computed attributes
        $batches->getCollection()->transform(function ($batch) {
            return [
                'id' => $batch->id,
                'name' => $batch->name,
                'description' => $batch->description,
                'status' => $batch->status,
                'quality_level' => $batch->quality_level,
                'total_segments' => $batch->total_segments,
                'completed_segments' => $batch->completed_segments,
                'failed_segments' => $batch->failed_segments,
                'progress_percentage' => $batch->progress_percentage,
                'remaining_segments' => $batch->remaining_segments,
                'concurrent_jobs' => $batch->concurrent_jobs,
                'estimated_duration' => $batch->estimated_duration,
                'actual_duration' => $batch->actual_duration,
                'estimated_time_remaining' => $batch->estimated_time_remaining,
                'started_at' => $batch->started_at,
                'completed_at' => $batch->completed_at,
                'created_at' => $batch->created_at,
                'course' => $batch->truefireCourse ? [
                    'id' => $batch->truefireCourse->id,
                    'title' => $batch->truefireCourse->title,
                ] : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $batches,
        ]);
    }

    /**
     * Store a newly created batch test.
     *
     * @param \App\Http\Requests\CreateBatchTestRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(CreateBatchTestRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Create the batch
            $batch = AudioTestBatch::create($validated);

            // Estimate processing duration
            $batch->estimateDuration();

            Log::info('Audio test batch created', [
                'batch_id' => $batch->id,
                'user_id' => Auth::id(),
                'total_segments' => $batch->total_segments,
                'quality_level' => $batch->quality_level
            ]);

            // Dispatch the batch processing job
            BatchAudioExtractionJob::dispatch($batch);

            return response()->json([
                'success' => true,
                'message' => 'Batch test created and processing started',
                'data' => [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'status' => $batch->status,
                    'total_segments' => $batch->total_segments,
                    'estimated_duration' => $batch->estimated_duration,
                    'created_at' => $batch->created_at,
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create batch test', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'request_data' => $request->validated()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create batch test',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified batch test.
     *
     * @param \App\Models\AudioTestBatch $batch
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(AudioTestBatch $batch): JsonResponse
    {
        // Ensure user owns this batch
        if ($batch->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to batch test',
            ], 403);
        }

        $batch->load(['truefireCourse', 'transcriptionLogs.video']);

        // Get detailed progress information
        $logs = $batch->transcriptionLogs;
        $progressDetails = [
            'queued' => $logs->where('status', 'queued')->count(),
            'processing' => $logs->where('status', 'processing')->count(),
            'completed' => $logs->where('status', 'completed')->count(),
            'failed' => $logs->where('status', 'failed')->count(),
        ];

        // Get Laravel batch information if available
        $laravelBatchInfo = null;
        if ($batch->batch_job_id) {
            try {
                $laravelBatch = Bus::findBatch($batch->batch_job_id);
                if ($laravelBatch) {
                    $laravelBatchInfo = [
                        'id' => $laravelBatch->id,
                        'name' => $laravelBatch->name,
                        'total_jobs' => $laravelBatch->totalJobs,
                        'processed_jobs' => $laravelBatch->processedJobs(),
                        'pending_jobs' => $laravelBatch->pendingJobs,
                        'failed_jobs' => $laravelBatch->failedJobs,
                        'cancelled' => $laravelBatch->cancelled(),
                        'finished' => $laravelBatch->finished(),
                        'created_at' => $laravelBatch->createdAt,
                        'finished_at' => $laravelBatch->finishedAt,
                    ];
                }
            } catch (\Exception $e) {
                Log::warning('Failed to retrieve Laravel batch info', [
                    'batch_id' => $batch->id,
                    'laravel_batch_id' => $batch->batch_job_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $batch->id,
                'name' => $batch->name,
                'description' => $batch->description,
                'status' => $batch->status,
                'quality_level' => $batch->quality_level,
                'extraction_settings' => $batch->extraction_settings,
                'segment_ids' => $batch->segment_ids,
                'total_segments' => $batch->total_segments,
                'completed_segments' => $batch->completed_segments,
                'failed_segments' => $batch->failed_segments,
                'progress_percentage' => $batch->progress_percentage,
                'remaining_segments' => $batch->remaining_segments,
                'concurrent_jobs' => $batch->concurrent_jobs,
                'estimated_duration' => $batch->estimated_duration,
                'actual_duration' => $batch->actual_duration,
                'estimated_time_remaining' => $batch->estimated_time_remaining,
                'started_at' => $batch->started_at,
                'completed_at' => $batch->completed_at,
                'created_at' => $batch->created_at,
                'updated_at' => $batch->updated_at,
                'course' => $batch->truefireCourse ? [
                    'id' => $batch->truefireCourse->id,
                    'title' => $batch->truefireCourse->title,
                ] : null,
                'progress_details' => $progressDetails,
                'laravel_batch' => $laravelBatchInfo,
                'logs_count' => $logs->count(),
            ],
        ]);
    }

    /**
     * Update the specified batch test.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\AudioTestBatch $batch
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, AudioTestBatch $batch): JsonResponse
    {
        // Ensure user owns this batch
        if ($batch->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to batch test',
            ], 403);
        }

        // Only allow updates to certain fields and only if not processing
        if ($batch->isProcessing()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update batch while processing',
            ], 422);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
        ]);

        $batch->update($request->only(['name', 'description']));

        return response()->json([
            'success' => true,
            'message' => 'Batch test updated successfully',
            'data' => [
                'id' => $batch->id,
                'name' => $batch->name,
                'description' => $batch->description,
                'updated_at' => $batch->updated_at,
            ],
        ]);
    }

    /**
     * Cancel the specified batch test.
     *
     * @param \App\Models\AudioTestBatch $batch
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel(AudioTestBatch $batch): JsonResponse
    {
        // Ensure user owns this batch
        if ($batch->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to batch test',
            ], 403);
        }

        if (!$batch->isProcessing()) {
            return response()->json([
                'success' => false,
                'message' => 'Batch is not currently processing',
            ], 422);
        }

        try {
            // Cancel Laravel batch if it exists
            if ($batch->batch_job_id) {
                $laravelBatch = Bus::findBatch($batch->batch_job_id);
                if ($laravelBatch && !$laravelBatch->cancelled()) {
                    $laravelBatch->cancel();
                }
            }

            // Mark batch as cancelled
            $batch->markAsCancelled();

            Log::info('Batch test cancelled', [
                'batch_id' => $batch->id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Batch test cancelled successfully',
                'data' => [
                    'id' => $batch->id,
                    'status' => $batch->status,
                    'completed_at' => $batch->completed_at,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to cancel batch test', [
                'batch_id' => $batch->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel batch test',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retry the specified batch test.
     *
     * @param \App\Models\AudioTestBatch $batch
     * @return \Illuminate\Http\JsonResponse
     */
    public function retry(AudioTestBatch $batch): JsonResponse
    {
        // Ensure user owns this batch
        if ($batch->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to batch test',
            ], 403);
        }

        if ($batch->isProcessing()) {
            return response()->json([
                'success' => false,
                'message' => 'Batch is currently processing',
            ], 422);
        }

        try {
            // Reset batch status and counters
            $batch->update([
                'status' => 'pending',
                'completed_segments' => 0,
                'failed_segments' => 0,
                'started_at' => null,
                'completed_at' => null,
                'actual_duration' => null,
                'batch_job_id' => null,
            ]);

            // Reset associated transcription logs
            $batch->transcriptionLogs()->update([
                'status' => 'queued',
                'started_at' => now(),
                'completed_at' => null,
                'error_message' => null,
                'audio_extraction_started_at' => null,
                'audio_extraction_completed_at' => null,
                'audio_extraction_duration_seconds' => null,
                'total_processing_duration_seconds' => null,
                'progress_percentage' => 0,
            ]);

            // Dispatch new batch processing job
            BatchAudioExtractionJob::dispatch($batch);

            Log::info('Batch test retried', [
                'batch_id' => $batch->id,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Batch test retry started successfully',
                'data' => [
                    'id' => $batch->id,
                    'status' => $batch->status,
                    'total_segments' => $batch->total_segments,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to retry batch test', [
                'batch_id' => $batch->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retry batch test',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified batch test.
     *
     * @param \App\Models\AudioTestBatch $batch
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(AudioTestBatch $batch): JsonResponse
    {
        // Ensure user owns this batch
        if ($batch->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to batch test',
            ], 403);
        }

        if ($batch->isProcessing()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete batch while processing. Cancel it first.',
            ], 422);
        }

        try {
            $batchId = $batch->id;

            // Cancel Laravel batch if it exists
            if ($batch->batch_job_id) {
                try {
                    $laravelBatch = Bus::findBatch($batch->batch_job_id);
                    if ($laravelBatch && !$laravelBatch->cancelled()) {
                        $laravelBatch->cancel();
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to cancel Laravel batch during deletion', [
                        'batch_id' => $batch->id,
                        'laravel_batch_id' => $batch->batch_job_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Delete the batch (transcription logs will be set to null due to foreign key constraint)
            $batch->delete();

            Log::info('Batch test deleted', [
                'batch_id' => $batchId,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Batch test deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete batch test', [
                'batch_id' => $batch->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete batch test',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export batch test results.
     *
     * @param \App\Models\AudioTestBatch $batch
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export(AudioTestBatch $batch, Request $request)
    {
        // Ensure user owns this batch
        if ($batch->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to batch test',
            ], 403);
        }

        $format = $request->get('format', 'json');

        try {
            $batch->load(['truefireCourse', 'transcriptionLogs.video']);

            $exportData = [
                'batch_info' => [
                    'id' => $batch->id,
                    'name' => $batch->name,
                    'description' => $batch->description,
                    'status' => $batch->status,
                    'quality_level' => $batch->quality_level,
                    'total_segments' => $batch->total_segments,
                    'completed_segments' => $batch->completed_segments,
                    'failed_segments' => $batch->failed_segments,
                    'progress_percentage' => $batch->progress_percentage,
                    'estimated_duration' => $batch->estimated_duration,
                    'actual_duration' => $batch->actual_duration,
                    'started_at' => $batch->started_at,
                    'completed_at' => $batch->completed_at,
                    'created_at' => $batch->created_at,
                ],
                'course_info' => $batch->truefireCourse ? [
                    'id' => $batch->truefireCourse->id,
                    'title' => $batch->truefireCourse->title,
                ] : null,
                'results' => $batch->transcriptionLogs->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'video_id' => $log->video_id,
                        'video_name' => $log->video?->title ?? 'Unknown',
                        'status' => $log->status,
                        'batch_position' => $log->batch_position,
                        'processing_time_seconds' => $log->total_processing_duration_seconds,
                        'audio_file_size' => $log->audio_file_size,
                        'audio_duration_seconds' => $log->audio_duration_seconds,
                        'quality_metrics' => $log->audio_quality_metrics,
                        'error_message' => $log->error_message,
                        'started_at' => $log->started_at,
                        'completed_at' => $log->completed_at,
                    ];
                })->toArray(),
                'exported_at' => now()->toISOString(),
            ];

            if ($format === 'csv') {
                return $this->exportAsCsv($exportData, $batch);
            }

            return response()->json([
                'success' => true,
                'data' => $exportData,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to export batch test results', [
                'batch_id' => $batch->id,
                'user_id' => Auth::id(),
                'format' => $format,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export batch test results',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export batch results as CSV.
     *
     * @param array $data
     * @param \App\Models\AudioTestBatch $batch
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function exportAsCsv(array $data, AudioTestBatch $batch)
    {
        $filename = "batch-test-{$batch->id}-results-" . now()->format('Y-m-d-H-i-s') . '.csv';

        return Response::streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            // Write headers
            fputcsv($handle, [
                'ID', 'Video ID', 'Video Name', 'Status', 'Batch Position',
                'Processing Time (s)', 'Audio File Size', 'Audio Duration (s)',
                'Error Message', 'Started At', 'Completed At'
            ]);

            // Write data rows
            foreach ($data['results'] as $result) {
                fputcsv($handle, [
                    $result['id'],
                    $result['video_id'],
                    $result['video_name'],
                    $result['status'],
                    $result['batch_position'],
                    $result['processing_time_seconds'],
                    $result['audio_file_size'],
                    $result['audio_duration_seconds'],
                    $result['error_message'],
                    $result['started_at'],
                    $result['completed_at'],
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Get batch processing statistics.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics(Request $request): JsonResponse
    {
        $userId = Auth::id();
        $days = $request->get('days', 30);

        $stats = [
            'total_batches' => AudioTestBatch::forUser($userId)->count(),
            'recent_batches' => AudioTestBatch::forUser($userId)->recent($days)->count(),
            'status_breakdown' => AudioTestBatch::forUser($userId)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'quality_level_breakdown' => AudioTestBatch::forUser($userId)
                ->selectRaw('quality_level, COUNT(*) as count')
                ->groupBy('quality_level')
                ->pluck('count', 'quality_level')
                ->toArray(),
            'total_segments_processed' => AudioTestBatch::forUser($userId)->sum('completed_segments'),
            'total_segments_failed' => AudioTestBatch::forUser($userId)->sum('failed_segments'),
            'average_processing_time' => AudioTestBatch::forUser($userId)
                ->whereNotNull('actual_duration')
                ->avg('actual_duration'),
            'recent_activity' => AudioTestBatch::forUser($userId)
                ->recent(7)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['id', 'name', 'status', 'total_segments', 'completed_segments', 'created_at'])
                ->toArray(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
