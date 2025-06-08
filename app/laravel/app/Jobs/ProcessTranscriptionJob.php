<?php

namespace App\Jobs;

use App\Models\TranscriptionLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessTranscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The transcription log instance.
     *
     * @var \App\Models\TranscriptionLog
     */
    protected $transcriptionLog;

    /**
     * The transcription preset to use.
     *
     * @var string|null
     */
    protected $transcriptionPreset;

    /**
     * Create a new job instance.
     */
    public function __construct(TranscriptionLog $transcriptionLog, ?string $transcriptionPreset = null)
    {
        $this->transcriptionLog = $transcriptionLog;
        $this->transcriptionPreset = $transcriptionPreset;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Update status to processing
        $this->transcriptionLog->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);

        try {
            $pythonServiceUrl = env('PYTHON_SERVICE_URL', 'http://transcription-service:5000');
            
            // Determine transcription preset to use
            $preset = $this->determineTranscriptionPreset();
            
            // Log that we're attempting to connect to the Python service
            Log::info('Attempting to connect to Python service', [
                'job_id' => $this->transcriptionLog->job_id,
                'service_url' => $pythonServiceUrl,
                'transcription_preset' => $preset
            ]);

            // Prepare request data with preset information
            $requestData = $this->transcriptionLog->request_data;
            $requestData['transcription_preset'] = $preset;

            // Send request to the Python service
            $response = Http::timeout(120)->post("{$pythonServiceUrl}/process", [
                'job_id' => $this->transcriptionLog->job_id,
                'data' => $requestData
            ]);

            if ($response->successful()) {
                Log::info('Successfully sent job to Python service', [
                    'job_id' => $this->transcriptionLog->job_id,
                    'response' => $response->json()
                ]);

                // The Python service will update the job status via callback
                // But we'll update it here as well to "processing" since we know
                // the Python service has received the job
                $this->transcriptionLog->update([
                    'status' => 'processing',
                    'preset_used' => $preset,
                    'response_data' => [
                        'message' => 'Job sent to Python service',
                        'timestamp' => now()->toIso8601String(),
                        'python_response' => $response->json(),
                        'transcription_preset' => $preset
                    ],
                ]);
            } else {
                throw new \Exception('Python service returned error: ' . $response->body());
            }

            Log::info('Transcription job dispatch completed', [
                'job_id' => $this->transcriptionLog->job_id
            ]);
        } catch (\Exception $e) {
            // Log the error and update the transcription log
            Log::error('Failed to process transcription job', [
                'job_id' => $this->transcriptionLog->job_id,
                'error' => $e->getMessage()
            ]);

            $this->transcriptionLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            // Store preset used even in failure case
            $this->transcriptionLog->update([
                'preset_used' => $preset ?? $this->determineTranscriptionPreset()
            ]);
        }
    }

    /**
     * Determine which transcription preset to use
     */
    protected function determineTranscriptionPreset(): string
    {
        // If preset was provided in constructor, use it
        if ($this->transcriptionPreset) {
            return $this->transcriptionPreset;
        }

        // Try to get preset from course if video_id is available in request data
        if (isset($this->transcriptionLog->request_data['video_id'])) {
            $videoId = $this->transcriptionLog->request_data['video_id'];
            
            // Find video and get course preset
            $video = \App\Models\Video::find($videoId);
            if ($video && $video->course_id) {
                $coursePreset = \App\Models\CourseTranscriptionPreset::getPresetForCourse($video->course_id);
                if ($coursePreset) {
                    Log::info('Using course transcription preset', [
                        'job_id' => $this->transcriptionLog->job_id,
                        'video_id' => $videoId,
                        'course_id' => $video->course_id,
                        'preset' => $coursePreset
                    ]);
                    return $coursePreset;
                }
            }
        }

        // Try to get preset from TrueFire course if truefire_course_id is available
        if (isset($this->transcriptionLog->request_data['truefire_course_id'])) {
            $courseId = $this->transcriptionLog->request_data['truefire_course_id'];
            $coursePreset = \App\Models\CourseTranscriptionPreset::getPresetForCourse($courseId);
            if ($coursePreset) {
                Log::info('Using TrueFire course transcription preset', [
                    'job_id' => $this->transcriptionLog->job_id,
                    'truefire_course_id' => $courseId,
                    'preset' => $coursePreset
                ]);
                return $coursePreset;
            }
        }

        // Fall back to default preset
        $defaultPreset = config('transcription_presets.default_preset', 'balanced');
        Log::info('Using default transcription preset', [
            'job_id' => $this->transcriptionLog->job_id,
            'preset' => $defaultPreset
        ]);
        
        return $defaultPreset;
    }
}
