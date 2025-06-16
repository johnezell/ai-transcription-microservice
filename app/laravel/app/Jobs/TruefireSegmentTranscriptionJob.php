<?php

namespace App\Jobs;

use App\Models\TruefireSegmentProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TruefireSegmentTranscriptionJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public $timeout = 600; // 10 minutes for transcription
    public $tries = 2;

    protected $processing;
    protected $transcriptionPreset;
    protected $enableAnalyticsProcessing;

    /**
     * Create a new job instance.
     */
    public function __construct(TruefireSegmentProcessing $processing, string $transcriptionPreset = 'balanced', bool $enableAnalyticsProcessing = true)
    {
        $this->processing = $processing;
        $this->transcriptionPreset = $transcriptionPreset;
        $this->enableAnalyticsProcessing = $enableAnalyticsProcessing;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting TrueFire segment transcription', [
                'segment_id' => $this->processing->segment_id,
                'course_id' => $this->processing->course_id,
                'transcription_preset' => $this->transcriptionPreset,
                'enable_analytics_processing' => $this->enableAnalyticsProcessing,
                'job_id' => $this->job->getJobId()
            ]);
            
            // ENHANCED: Early performance video detection to prevent transcription service bottlenecks
            if ($this->processing->audio_path) {
                $isPerformanceVideo = $this->detectPerformanceVideoFromAudio($this->processing->audio_path);
                
                if ($isPerformanceVideo) {
                    Log::info('Performance video detected in transcription job - creating minimal transcript instead', [
                        'segment_id' => $this->processing->segment_id,
                        'audio_path' => $this->processing->audio_path,
                        'detection_reason' => $isPerformanceVideo['reason']
                    ]);
                    
                    // Create minimal transcript for performance video
                    $this->createPerformanceVideoTranscript($isPerformanceVideo);
                    
                    // Mark as completed with performance video flag
                    $this->processing->update([
                        'status' => 'completed',
                        'progress_percentage' => 100,
                        'transcript_text' => '[Instrumental Performance]',
                        'transcription_completed_at' => now(),
                        'processing_metadata' => json_encode([
                            'performance_video' => true,
                            'detection_reason' => $isPerformanceVideo['reason'],
                            'skipped_transcription_service' => true,
                            'auto_generated_transcript' => true
                        ])
                    ]);
                    
                    Log::info('Performance video transcript created successfully', [
                        'segment_id' => $this->processing->segment_id
                    ]);
                    
                    return; // Exit early, no need to call transcription service
                }
            }

            // Check if audio file exists (handle both storage and D drive paths)
            $audioExists = false;
            if (!empty($this->processing->audio_path)) {
                if (str_contains($this->processing->audio_path, '/mnt/d_drive/')) {
                    // For D drive files, use file_exists
                    $audioExists = file_exists($this->processing->audio_path);
                } else {
                    // For storage files, use Storage facade
                    $audioExists = Storage::exists($this->processing->audio_path);
                }
            }
            
            if (!$audioExists) {
                throw new \Exception('Audio file not found for transcription: ' . $this->processing->audio_path);
            }

            // Get transcription service URL from environment (same as existing jobs)
            $transcriptionServiceUrl = env('TRANSCRIPTION_SERVICE_URL', 'http://transcription-service:5000');

            // Get audio file URL (handle both storage and D drive paths)
            if (str_contains($this->processing->audio_path, '/mnt/d_drive/')) {
                // For D drive files, the transcription service expects the actual file path
                $audioUrl = $this->processing->audio_path;
            } else {
                // For storage files, use Storage URL
                $audioUrl = Storage::url($this->processing->audio_path);
            }

            // Prepare the transcription request data with timeout handling
            $requestData = [
                'job_id' => $this->job->getJobId(),
                'audio_path' => $audioUrl,
                'course_id' => $this->processing->course_id,
                'segment_id' => $this->processing->segment_id,
                'preset' => $this->transcriptionPreset,
                'enable_intelligent_selection' => true,
                'enable_analytics_processing' => $this->enableAnalyticsProcessing,
                'max_processing_time' => 300, // 5 minutes max processing time
                'enable_early_performance_detection' => true,
                'timeout_fallback_enabled' => true
            ];

            Log::info('Sending TrueFire segment transcription request', [
                'url' => $transcriptionServiceUrl . '/process',
                'segment_id' => $this->processing->segment_id,
                'preset' => $this->transcriptionPreset,
                'audio_path' => $this->processing->audio_path
            ]);

            // Send request to transcription service with shorter timeout to prevent hanging
            $response = Http::timeout(120)->post($transcriptionServiceUrl . '/process', $requestData);

            if (!$response->successful()) {
                throw new \Exception('Transcription service request failed: ' . $response->body());
            }

            $responseData = $response->json();

            if (!isset($responseData['job_id'])) {
                throw new \Exception('Transcription service did not return job_id');
            }

            // Processing record already updated with start time when job was dispatched
            // Status and progress already set, just proceed with service call

            Log::info('TrueFire segment transcription request sent successfully', [
                'segment_id' => $this->processing->segment_id,
                'service_job_id' => $responseData['job_id']
            ]);

        } catch (\Exception $e) {
            Log::error('TrueFire segment transcription failed', [
                'segment_id' => $this->processing->segment_id,
                'course_id' => $this->processing->course_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Mark as failed
            $this->processing->markAsFailed('Transcription failed: ' . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TrueFire segment transcription job failed permanently', [
            'segment_id' => $this->processing->segment_id,
            'course_id' => $this->processing->course_id,
            'error' => $exception->getMessage()
        ]);

        // Mark processing as failed
        $this->processing->markAsFailed('Transcription job failed: ' . $exception->getMessage());
    }
    
    /**
     * Detect if an audio file likely represents a performance video with minimal speech
     * This prevents unnecessary transcription service calls that would fail with empty replies
     */
    private function detectPerformanceVideoFromAudio($audioPath)
    {
        try {
            if (!file_exists($audioPath)) {
                return false;
            }
            
            // Get basic file info
            $fileSize = filesize($audioPath);
            $duration = $this->getAudioDuration($audioPath);
            
            Log::debug('Analyzing audio for performance video detection in transcription job', [
                'audio_path' => $audioPath,
                'file_size' => $fileSize,
                'duration' => $duration
            ]);
            
            // Detection criteria (same as in controller)
            
            // 1. Very small files (less than 100KB) are likely minimal content
            if ($fileSize < 100 * 1024) {
                return [
                    'is_performance' => true,
                    'reason' => 'very_small_file',
                    'details' => "File size: " . round($fileSize / 1024, 1) . "KB"
                ];
            }
            
            // 2. Very short duration (less than 10 seconds) likely minimal content
            if ($duration && $duration < 10) {
                return [
                    'is_performance' => true,
                    'reason' => 'very_short_duration',
                    'details' => "Duration: {$duration} seconds"
                ];
            }
            
            // 3. Low bitrate might indicate compressed/minimal content
            if ($duration && $duration > 0) {
                $bitrate = ($fileSize * 8) / $duration; // bits per second
                $kbps = $bitrate / 1000;
                
                // If bitrate is extremely low (less than 32 kbps), might be minimal content
                if ($kbps < 32) {
                    return [
                        'is_performance' => true,
                        'reason' => 'very_low_bitrate',
                        'details' => "Bitrate: " . round($kbps, 1) . " kbps"
                    ];
                }
            }
            
            // Not detected as performance video
            return false;
            
        } catch (\Exception $e) {
            Log::warning('Performance video detection failed in transcription job, proceeding with transcription service', [
                'audio_path' => $audioPath,
                'error' => $e->getMessage()
            ]);
            
            // If detection fails, proceed with transcription service to be safe
            return false;
        }
    }
    
    /**
     * Get audio duration using ffprobe if available
     */
    private function getAudioDuration($audioPath)
    {
        try {
            // Try to use ffprobe to get duration
            $command = "ffprobe -v quiet -show_entries format=duration -of csv=p=0 " . escapeshellarg($audioPath);
            $output = shell_exec($command);
            
            if ($output) {
                $duration = floatval(trim($output));
                if ($duration > 0) {
                    return $duration;
                }
            }
        } catch (\Exception $e) {
            Log::debug('Could not get audio duration with ffprobe in transcription job', [
                'audio_path' => $audioPath,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }
    
    /**
     * Create minimal transcript JSON for performance videos
     */
    private function createPerformanceVideoTranscript($detectionInfo)
    {
        try {
            // Create minimal transcript JSON structure
            $transcriptData = [
                'task' => 'transcribe',
                'language' => 'en',
                'duration' => 0.0,
                'text' => '[Instrumental Performance]',
                'segments' => [
                    [
                        'id' => 0,
                        'seek' => 0,
                        'start' => 0.0,
                        'end' => 0.0,
                        'text' => '[Instrumental Performance]',
                        'temperature' => 0.0,
                        'avg_logprob' => -0.1,
                        'compression_ratio' => 1.0,
                        'no_speech_prob' => 0.99,
                        'words' => [
                            [
                                'word' => '[Instrumental Performance]',
                                'start' => 0.0,
                                'end' => 0.0,
                                'probability' => 1.0
                            ]
                        ]
                    ]
                ],
                'performance_video_metadata' => [
                    'auto_generated' => true,
                    'detection_reason' => $detectionInfo['reason'],
                    'detection_details' => $detectionInfo['details'],
                    'generated_at' => now()->toISOString(),
                    'generated_by' => 'TruefireSegmentTranscriptionJob'
                ],
                'speech_activity' => [
                    'speech_activity_ratio' => 0.0,
                    'total_duration_seconds' => 0.0,
                    'speaking_rate_wpm' => 0.0,
                    'pause_count' => 0
                ],
                'content_quality' => [
                    'total_words' => 0,
                    'unique_words' => 0,
                    'vocabulary_richness' => 0.0,
                    'music_term_count' => 0
                ],
                'teaching_patterns' => [
                    'content_classification' => [
                        'primary_type' => 'performance',
                        'confidence' => 0.95,
                        'content_focus' => 'instrumental_performance'
                    ]
                ],
                'quality_metrics' => [
                    'overall_quality_score' => 1.0, // High quality for what it is (performance)
                    'overall_confidence' => 1.0
                ]
            ];
            
            // Store transcript JSON in processing record
            $this->processing->update([
                'transcript_json' => $transcriptData,
                'transcript_json_path' => null // No physical file created
            ]);
            
            Log::info('Created minimal transcript for performance video in transcription job', [
                'segment_id' => $this->processing->segment_id,
                'detection_reason' => $detectionInfo['reason'],
                'detection_details' => $detectionInfo['details']
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to create performance video transcript in transcription job', [
                'segment_id' => $this->processing->segment_id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
