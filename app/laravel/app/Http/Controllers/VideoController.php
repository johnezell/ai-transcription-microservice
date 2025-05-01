<?php

namespace App\Http\Controllers;

use App\Jobs\AudioExtractionJob;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class VideoController extends Controller
{
    /**
     * Display a listing of the videos.
     */
    public function index()
    {
        $videos = Video::latest()->get();
        
        return Inertia::render('Videos/Index', [
            'videos' => $videos,
        ]);
    }
    
    /**
     * Show the form for creating a new video.
     */
    public function create()
    {
        return Inertia::render('Videos/Create');
    }
    
    /**
     * Store a newly uploaded video.
     */
    public function store(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimetypes:video/mp4,video/mpeg,video/quicktime', // No size limit
        ]);
        
        $file = $request->file('video');
        $originalFilename = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        
        // Create a new video record first to get its UUID
        $video = Video::create([
            'original_filename' => $originalFilename,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'status' => 'uploading',
            'metadata' => [
                'uploaded_at' => now()->toIso8601String(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        ]);
        
        try {
            // Use the video UUID as the job ID for consistent folder structure
            $jobId = $video->id;
            
            // Create job directory structure
            $s3JobPath = "s3/jobs/{$jobId}";
            Storage::disk('public')->makeDirectory($s3JobPath);
            
            // Store the video with a standardized filename
            $videoFilename = "video.{$extension}";
            $videoPath = "{$s3JobPath}/{$videoFilename}";
            
            // Store the file
            $result = Storage::disk('public')->putFileAs($s3JobPath, $file, $videoFilename);
            
            if (!$result) {
                throw new \Exception("Failed to store video file");
            }
            
            // Update the video record with the storage path
            $video->update([
                'storage_path' => $videoPath,
                's3_key' => $videoFilename,
                'status' => 'uploaded',
            ]);
            
            // Log the file storage details for debugging
            Log::info('Video file upload', [
                'job_id' => $jobId,
                'original_filename' => $originalFilename,
                'storage_path' => $videoPath,
                'file_size' => $file->getSize(),
                'storage_result' => $result,
                'exists' => Storage::disk('public')->exists($videoPath),
                'full_path' => Storage::disk('public')->path($videoPath),
            ]);
            
            // Automatically start audio extraction
            // Mark video as processing
            $video->update([
                'status' => 'processing'
            ]);

            // Log the dispatch
            Log::info('Auto-dispatching audio extraction job for video after upload', [
                'video_id' => $video->id,
                'storage_path' => $videoPath
            ]);

            // Dispatch audio extraction job to queue
            \App\Jobs\AudioExtractionJob::dispatch($video);
            
            return redirect()->route('videos.index')
                ->with('success', 'Video uploaded successfully and processing started.');
                
        } catch (\Exception $e) {
            // If anything goes wrong, update the status to failed
            $video->update(['status' => 'failed']);
            
            Log::error('Video upload failed', [
                'error' => $e->getMessage(),
                'video_id' => $video->id
            ]);
            
            return redirect()->route('videos.index')
                ->with('error', 'Failed to upload video: ' . $e->getMessage());
        }
    }
    
    /**
     * Display the specified video.
     */
    public function show(Video $video)
    {
        return Inertia::render('Videos/Show', [
            'video' => $video,
        ]);
    }
    
    /**
     * Remove the specified video from storage.
     */
    public function destroy(Video $video)
    {
        try {
            // Get job directory path based on video ID
            $jobPath = "s3/jobs/{$video->id}";
            
            // Check if the job directory exists
            if (Storage::disk('public')->exists($jobPath)) {
                // Delete the entire job directory with all its contents
                Storage::disk('public')->deleteDirectory($jobPath);
                Log::info('Deleted job directory for video', [
                    'video_id' => $video->id,
                    'job_path' => $jobPath
                ]);
            } else {
                // Fallback to just deleting the video file if job directory doesn't exist
                if ($video->storage_path && Storage::disk('public')->exists($video->storage_path)) {
                    Storage::disk('public')->delete($video->storage_path);
                    Log::info('Deleted video file only', [
                        'video_id' => $video->id,
                        'storage_path' => $video->storage_path
                    ]);
                }
            }
            
            // Delete the record
            $video->delete();
            
            return redirect()->route('videos.index')
                ->with('success', 'Video and all associated files deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Error deleting video', [
                'video_id' => $video->id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->route('videos.index')
                ->with('error', 'Error deleting video: ' . $e->getMessage());
        }
    }
    
    /**
     * Request transcription for the video.
     */
    public function requestTranscription(Video $video)
    {
        try {
            // Check if file exists
            if (!Storage::disk('public')->exists($video->storage_path)) {
                Log::error('Video file not found', [
                    'video_id' => $video->id,
                    'storage_path' => $video->storage_path
                ]);
                
                return redirect()->route('videos.show', $video)
                    ->with('error', 'Video file not found. Please try uploading again.');
            }
            
            // Mark video as processing
            $video->update([
                'status' => 'processing'
            ]);

            // Log the dispatch
            Log::info('Dispatching audio extraction job for video', [
                'video_id' => $video->id,
                'storage_path' => $video->storage_path
            ]);

            // Dispatch audio extraction job to queue
            \App\Jobs\AudioExtractionJob::dispatch($video);
            
            return redirect()->route('videos.show', $video)
                ->with('success', 'Transcription process started. Check back later for results.');
        } catch (\Exception $e) {
            // Handle exception
            Log::error('Exception when requesting transcription', [
                'video_id' => $video->id,
                'error' => $e->getMessage()
            ]);
            
            $video->update([
                'status' => 'failed'
            ]);
            
            return redirect()->route('videos.show', $video)
                ->with('error', 'An error occurred: ' . $e->getMessage());
        }
    }
}
