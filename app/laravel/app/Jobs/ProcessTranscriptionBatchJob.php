<?php

namespace App\Jobs;

use App\Models\TruefireSegmentProcessing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessTranscriptionBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // 2 hours for batch processing
    public $tries = 3;

    protected $batchSize;
    protected $preset;
    protected $maxWorkers;
    protected $courseId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $batchSize = 4, string $preset = 'balanced', int $maxWorkers = 4, int $courseId = null)
    {
        $this->batchSize = $batchSize;
        $this->preset = $preset;
        $this->maxWorkers = $maxWorkers;
        $this->courseId = $courseId;
        
        Log::info('ProcessTranscriptionBatchJob created', [
            'batch_size' => $batchSize,
            'preset' => $preset,
            'max_workers' => $maxWorkers,
            'course_id' => $courseId,
            'workflow_step' => 'batch_job_creation'
        ]);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting batch transcription processing', [
                'batch_size' => $this->batchSize,
                'preset' => $this->preset,
                'max_workers' => $this->maxWorkers,
                'course_id' => $this->courseId,
                'workflow_step' => 'batch_processing_start'
            ]);

            // Get pending segments that have completed audio extraction
            $pendingSegments = TruefireSegmentProcessing::where('status', 'audio_extracted')
                ->where('transcription_completed_at', null)
                ->when($this->courseId, function ($query) {
                    return $query->where('course_id', $this->courseId);
                })
                ->limit($this->batchSize)
                ->get();

            if ($pendingSegments->isEmpty()) {
                Log::info('No segments ready for batch transcription processing', [
                    'course_id' => $this->courseId,
                    'workflow_step' => 'no_segments_found'
                ]);
                return;
            }

            // Mark segments as transcribing to prevent other jobs from picking them up
            $segmentIds = $pendingSegments->pluck('id')->toArray();
            TruefireSegmentProcessing::whereIn('id', $segmentIds)
                ->update([
                    'status' => 'transcribing',
                    'transcription_started_at' => now()
                ]);

            Log::info('Marked segments as transcribing', [
                'segment_ids' => $segmentIds,
                'count' => count($segmentIds),
                'workflow_step' => 'segments_marked_transcribing'
            ]);

            // Build audio file paths for batch processing
            $audioPaths = [];
            $segmentMapping = []; // Map audio paths to segment records

            foreach ($pendingSegments as $segment) {
                $audioPath = $this->getAudioPath($segment);
                
                if ($audioPath && Storage::disk('d_drive')->exists($audioPath)) {
                    $fullPath = Storage::disk('d_drive')->path($audioPath);
                    $audioPaths[] = $fullPath;
                    $segmentMapping[$fullPath] = $segment;
                } else {
                    Log::warning('Audio file not found for segment', [
                        'segment_id' => $segment->segment_id,
                        'course_id' => $segment->course_id,
                        'expected_path' => $audioPath,
                        'workflow_step' => 'audio_file_missing'
                    ]);
                    
                    // Mark segment as failed
                    $segment->update([
                        'status' => 'failed',
                        'error_message' => 'Audio file not found for transcription',
                        'transcription_completed_at' => now()
                    ]);
                }
            }

            if (empty($audioPaths)) {
                Log::error('No valid audio files found for batch processing', [
                    'segment_ids' => $segmentIds,
                    'workflow_step' => 'no_valid_audio_files'
                ]);
                
                // Mark remaining segments as failed
                TruefireSegmentProcessing::whereIn('id', $segmentIds)
                    ->where('status', 'transcribing')
                    ->update([
                        'status' => 'failed',
                        'error_message' => 'No valid audio files found for batch processing',
                        'transcription_completed_at' => now()
                    ]);
                return;
            }

            Log::info('Prepared audio files for batch processing', [
                'audio_files_count' => count($audioPaths),
                'segment_ids' => $segmentIds,
                'workflow_step' => 'audio_files_prepared'
            ]);

            // Call the parallel transcription service
            $response = $this->callParallelTranscriptionService($audioPaths, $segmentIds);

            if ($response && $response['success']) {
                $this->processBatchResults($response, $segmentMapping);
            } else {
                throw new \Exception('Parallel transcription service failed: ' . ($response['message'] ?? 'Unknown error'));
            }

        } catch (\Exception $e) {
            Log::error('Batch transcription processing failed', [
                'error' => $e->getMessage(),
                'batch_size' => $this->batchSize,
                'preset' => $this->preset,
                'course_id' => $this->courseId,
                'workflow_step' => 'batch_processing_failed'
            ]);

            // Mark any transcribing segments as failed
            if (isset($segmentIds)) {
                TruefireSegmentProcessing::whereIn('id', $segmentIds)
                    ->where('status', 'transcribing')
                    ->update([
                        'status' => 'failed',
                        'error_message' => 'Batch transcription failed: ' . $e->getMessage(),
                        'transcription_completed_at' => now()
                    ]);
            }

            throw $e;
        }
    }

    /**
     * Get the audio file path for a segment.
     */
    protected function getAudioPath(TruefireSegmentProcessing $segment): ?string
    {
        // Audio files are stored as: truefire-courses/{course_id}/{segment_id}.wav
        return "truefire-courses/{$segment->course_id}/{$segment->segment_id}.wav";
    }

    /**
     * Call the parallel transcription service.
     */
    protected function callParallelTranscriptionService(array $audioPaths, array $segmentIds): ?array
    {
        $jobId = 'batch_' . implode('_', $segmentIds) . '_' . time();
        
        $payload = [
            'job_id' => $jobId,
            'audio_paths' => $audioPaths,
            'preset' => $this->preset,
            'max_workers' => $this->maxWorkers,
            'course_id' => $this->courseId
        ];

        Log::info('Calling parallel transcription service', [
            'job_id' => $jobId,
            'audio_files_count' => count($audioPaths),
            'preset' => $this->preset,
            'max_workers' => $this->maxWorkers,
            'workflow_step' => 'calling_transcription_service'
        ]);

        try {
            $response = Http::timeout(7200) // 2 hour timeout for batch processing
                ->post('http://transcription:5000/transcribe-parallel', $payload);

            if ($response->successful()) {
                $responseData = $response->json();
                
                Log::info('Parallel transcription service completed successfully', [
                    'job_id' => $jobId,
                    'success_rate' => $responseData['batch_summary']['success_rate'] ?? 'unknown',
                    'total_time' => $responseData['batch_summary']['total_processing_time'] ?? 'unknown',
                    'workflow_step' => 'transcription_service_completed'
                ]);
                
                return $responseData;
            } else {
                Log::error('Parallel transcription service failed', [
                    'job_id' => $jobId,
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'workflow_step' => 'transcription_service_failed'
                ]);
                
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Error calling parallel transcription service', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'workflow_step' => 'transcription_service_error'
            ]);
            
            return null;
        }
    }

    /**
     * Process the batch transcription results.
     */
    protected function processBatchResults(array $response, array $segmentMapping): void
    {
        $results = $response['results'] ?? [];
        $batchSummary = $response['batch_summary'] ?? [];
        
        Log::info('Processing batch transcription results', [
            'total_results' => count($results),
            'successful_files' => $batchSummary['successful_files'] ?? 0,
            'failed_files' => $batchSummary['failed_files'] ?? 0,
            'workflow_step' => 'processing_batch_results'
        ]);

        foreach ($results as $result) {
            $audioPath = $result['batch_metadata']['original_path'] ?? null;
            
            if (!$audioPath || !isset($segmentMapping[$audioPath])) {
                Log::warning('Could not map transcription result to segment', [
                    'audio_path' => $audioPath,
                    'workflow_step' => 'result_mapping_failed'
                ]);
                continue;
            }

            $segment = $segmentMapping[$audioPath];
            
            if ($result['error'] ?? false) {
                // Handle failed transcription
                $segment->update([
                    'status' => 'failed',
                    'error_message' => $result['error_message'] ?? 'Transcription failed',
                    'transcription_completed_at' => now(),
                    'transcription_response' => json_encode($result)
                ]);
                
                Log::warning('Transcription failed for segment', [
                    'segment_id' => $segment->segment_id,
                    'course_id' => $segment->course_id,
                    'error' => $result['error_message'] ?? 'Unknown error',
                    'workflow_step' => 'segment_transcription_failed'
                ]);
            } else {
                // Handle successful transcription
                $this->saveTranscriptionFiles($segment, $result);
                
                $segment->update([
                    'status' => 'transcription_completed',
                    'transcription_completed_at' => now(),
                    'confidence_score' => $result['confidence_score'] ?? 0,
                    'transcription_response' => json_encode($result)
                ]);
                
                Log::info('Transcription completed for segment', [
                    'segment_id' => $segment->segment_id,
                    'course_id' => $segment->course_id,
                    'confidence_score' => $result['confidence_score'] ?? 0,
                    'processing_time' => $result['whisperx_processing']['processing_times']['total_seconds'] ?? 'unknown',
                    'workflow_step' => 'segment_transcription_completed'
                ]);
            }
        }
    }

    /**
     * Save transcription files for a segment.
     */
    protected function saveTranscriptionFiles(TruefireSegmentProcessing $segment, array $result): void
    {
        $courseDir = "truefire-courses/{$segment->course_id}";
        
        try {
            // Save transcript text file
            $transcriptPath = "{$courseDir}/{$segment->segment_id}_transcript.txt";
            Storage::disk('d_drive')->put($transcriptPath, $result['text'] ?? '');
            
            // Save transcript JSON file  
            $jsonPath = "{$courseDir}/{$segment->segment_id}_transcript.json";
            Storage::disk('d_drive')->put($jsonPath, json_encode($result, JSON_PRETTY_PRINT));
            
            // Generate and save SRT file
            if (isset($result['segments']) && !empty($result['segments'])) {
                $srtContent = $this->generateSrtContent($result['segments']);
                $srtPath = "{$courseDir}/{$segment->segment_id}_transcript.srt";
                Storage::disk('d_drive')->put($srtPath, $srtContent);
            }
            
            Log::info('Saved transcription files for segment', [
                'segment_id' => $segment->segment_id,
                'course_id' => $segment->course_id,
                'files_saved' => ['txt', 'json', 'srt'],
                'workflow_step' => 'transcription_files_saved'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error saving transcription files', [
                'segment_id' => $segment->segment_id,
                'course_id' => $segment->course_id,
                'error' => $e->getMessage(),
                'workflow_step' => 'transcription_files_save_error'
            ]);
        }
    }

    /**
     * Generate SRT content from segments.
     */
    protected function generateSrtContent(array $segments): string
    {
        $srtContent = '';
        
        foreach ($segments as $index => $segment) {
            $startTime = $this->formatSrtTime($segment['start'] ?? 0);
            $endTime = $this->formatSrtTime($segment['end'] ?? 0);
            $text = trim($segment['text'] ?? '');
            
            if ($text) {
                $srtContent .= ($index + 1) . "\n";
                $srtContent .= "{$startTime} --> {$endTime}\n";
                $srtContent .= "{$text}\n\n";
            }
        }
        
        return $srtContent;
    }

    /**
     * Format time for SRT format.
     */
    protected function formatSrtTime(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);
        $milliseconds = round(($seconds - floor($seconds)) * 1000);
        
        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $milliseconds);
    }
} 