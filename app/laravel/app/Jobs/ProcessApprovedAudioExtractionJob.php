<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessApprovedAudioExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The video model instance.
     *
     * @var \App\Models\Video
     */
    protected $video;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\Video  $video
     * @return void
     */
    public function __construct(Video $video)
    {
        $this->video = $video;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            // Verify that the audio extraction has been approved
            if (!$this->video->audio_extraction_approved) {
                Log::warning('ProcessApprovedAudioExtractionJob called for video without approval', [
                    'video_id' => $this->video->id,
                    'audio_extraction_approved' => $this->video->audio_extraction_approved
                ]);
                return;
            }

            // Verify that the audio file exists
            if (empty($this->video->audio_path)) {
                Log::error('ProcessApprovedAudioExtractionJob called for video without audio file', [
                    'video_id' => $this->video->id,
                    'audio_path' => $this->video->audio_path
                ]);
                
                $this->video->update([
                    'status' => 'failed',
                    'error_message' => 'Audio file not found for transcription'
                ]);
                
                return;
            }

            Log::info('Processing approved audio extraction for transcription', [
                'video_id' => $this->video->id,
                'audio_path' => $this->video->audio_path,
                'approved_at' => $this->video->audio_extraction_approved_at,
                'approved_by' => $this->video->audio_extraction_approved_by
            ]);

            // Update status to indicate transcription is starting
            $this->video->update([
                'status' => 'transcribing'
            ]);

            // Dispatch the transcription job
            TranscriptionJob::dispatch($this->video);

            Log::info('Successfully dispatched transcription job for approved audio', [
                'video_id' => $this->video->id
            ]);

        } catch (\Exception $e) {
            $errorMessage = 'Exception in ProcessApprovedAudioExtractionJob: ' . $e->getMessage();
            Log::error($errorMessage, [
                'video_id' => $this->video->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Update video with failure status
            $this->video->update([
                'status' => 'failed',
                'error_message' => $errorMessage
            ]);
        }
    }
} 