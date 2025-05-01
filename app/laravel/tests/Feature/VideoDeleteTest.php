<?php

namespace Tests\Feature;

use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_deletion_removes_all_associated_files()
    {
        // Create a fake disk for testing
        Storage::fake('public');
        
        // Create a test video
        $video = Video::create([
            'original_filename' => 'test_video.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1000,
            'status' => 'uploaded',
        ]);
        
        // Create job directory structure with test files
        $jobPath = "s3/jobs/{$video->id}";
        Storage::disk('public')->makeDirectory($jobPath);
        
        // Create test files
        Storage::disk('public')->put("{$jobPath}/video.mp4", 'test video content');
        Storage::disk('public')->put("{$jobPath}/audio.wav", 'test audio content');
        Storage::disk('public')->put("{$jobPath}/transcript.txt", 'test transcript content');
        
        // Update video with paths
        $video->update([
            'storage_path' => "{$jobPath}/video.mp4",
            'audio_path' => "{$jobPath}/audio.wav",
            'transcript_path' => "{$jobPath}/transcript.txt",
        ]);
        
        // Verify files exist
        Storage::disk('public')->assertExists("{$jobPath}/video.mp4");
        Storage::disk('public')->assertExists("{$jobPath}/audio.wav");
        Storage::disk('public')->assertExists("{$jobPath}/transcript.txt");
        
        // Send delete request
        $response = $this->delete(route('videos.destroy', $video));
        
        // Assert that the job directory no longer exists
        Storage::disk('public')->assertMissing($jobPath);
        
        // Assert that the video record was deleted from the database
        $this->assertDatabaseMissing('videos', ['id' => $video->id]);
        
        // Assert redirect with success message
        $response->assertRedirect(route('videos.index'));
        $response->assertSessionHas('success', 'Video and all associated files deleted successfully.');
    }
    
    public function test_deletes_only_video_file_when_job_directory_not_found()
    {
        // Create a fake disk for testing
        Storage::fake('public');
        
        // Create a test video without a job directory
        $video = Video::create([
            'original_filename' => 'test_video.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1000,
            'status' => 'uploaded',
            'storage_path' => 'videos/test.mp4',
        ]);
        
        // Create the video file directly (not in a job directory)
        Storage::disk('public')->put('videos/test.mp4', 'test video content');
        
        // Verify file exists
        Storage::disk('public')->assertExists('videos/test.mp4');
        
        // Send delete request
        $response = $this->delete(route('videos.destroy', $video));
        
        // Assert that the file no longer exists
        Storage::disk('public')->assertMissing('videos/test.mp4');
        
        // Assert that the video record was deleted from the database
        $this->assertDatabaseMissing('videos', ['id' => $video->id]);
        
        // Assert redirect with success message
        $response->assertRedirect(route('videos.index'));
        $response->assertSessionHas('success', 'Video and all associated files deleted successfully.');
    }
} 