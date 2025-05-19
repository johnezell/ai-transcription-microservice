<?php

namespace App\Http\Controllers;

use App\Jobs\AudioExtractionJob;
use App\Jobs\ThumbnailExtractionJob;
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
    public function index(Request $request)
    {
        $query = Video::with('course');
        
        // Handle search
        if ($request->has('search') && !empty($request->input('search'))) {
            $searchTerm = $request->input('search');
            $query->where(function($q) use ($searchTerm) {
                $q->where('original_filename', 'like', "%{$searchTerm}%")
                  ->orWhereHas('course', function($subQ) use ($searchTerm) {
                      $subQ->where('name', 'like', "%{$searchTerm}%");
                  });
            });
        }
        
        // Filter by status
        if ($request->has('status') && !empty($request->input('status'))) {
            $status = $request->input('status');
            $query->where('status', $status);
        }
        
        // Filter by course
        if ($request->has('course_id') && !empty($request->input('course_id'))) {
            $courseId = $request->input('course_id');
            $query->where('course_id', $courseId);
        }
        
        // Sort the results
        $query->latest();
        
        // Paginate the results
        $videos = $query->paginate(15)->withQueryString();
        
        // Get all courses for the dropdown
        $courses = \App\Models\Course::orderBy('name')->get();
        
        return Inertia::render('Videos/Index', [
            'videos' => $videos,
            'courses' => $courses,
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status', ''),
                'course_id' => $request->input('course_id', '')
            ],
            'statusOptions' => [
                ['value' => '', 'label' => 'All Statuses'],
                ['value' => 'completed', 'label' => 'Completed'],
                ['value' => 'processing', 'label' => 'Processing'],
                ['value' => 'is_processing', 'label' => 'Processing'],
                ['value' => 'uploaded', 'label' => 'Uploaded'],
                ['value' => 'transcribed', 'label' => 'Transcribed'],
                ['value' => 'failed', 'label' => 'Failed']
            ]
        ]);
    }
    
    /**
     * Show the form for creating a new video.
     */
    public function create()
    {
        $courses = \App\Models\Course::all();
        $presets = \App\Models\TranscriptionPreset::active()->get();
        $defaultPreset = \App\Models\TranscriptionPreset::default()->first();
        
        return Inertia::render('Videos/Create', [
            'courses' => $courses,
            'presets' => $presets,
            'defaultPresetId' => $defaultPreset ? $defaultPreset->id : null,
        ]);
    }
    
    /**
     * Store a newly uploaded video.
     */
    public function store(Request $request)
    {
        // Validate the request
        $request->validate([
            'videos' => 'required|array',
            'videos.*' => 'required|file|mimetypes:video/mp4,video/mpeg,video/quicktime',
            'course_id' => 'nullable|exists:courses,id',
            'lesson_number_start' => 'required_with:course_id|numeric|min:1',
            'preset_id' => 'nullable|exists:transcription_presets,id',
        ]);
        
        $videos = $request->file('videos');
        $courseId = $request->input('course_id');
        $lessonStart = $request->input('lesson_number_start', 1);
        $presetId = $request->input('preset_id');
        
        // If no preset was specified, use the default
        if (empty($presetId)) {
            $defaultPreset = \App\Models\TranscriptionPreset::default()->first();
            $presetId = $defaultPreset ? $defaultPreset->id : null;
        }
        
        $createdVideos = [];
        
        // Determine highest lesson number if course_id is provided
        $currentLessonNumber = $lessonStart;
        if ($courseId) {
            // Get the highest current lesson number in the course
            $highestLesson = \App\Models\Video::where('course_id', $courseId)
                ->max('lesson_number');
                
            // If there are existing lessons, make sure we start after them
            if ($highestLesson && $currentLessonNumber <= $highestLesson) {
                $currentLessonNumber = $highestLesson + 1;
            }
        }
        
        // Process each video file
        foreach ($videos as $index => $file) {
            $originalFilename = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            
            // Create a new video record
            $video = \App\Models\Video::create([
                'original_filename' => $originalFilename,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'status' => 'uploading',
                'course_id' => $courseId,
                'lesson_number' => $courseId ? $currentLessonNumber + $index : null,
                'preset_id' => $presetId,
                'metadata' => [
                    'uploaded_at' => now()->toIso8601String(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'batch_upload' => true,
                    'batch_index' => $index,
                ],
            ]);
            
            try {
                // Use the video UUID as the job ID for consistent folder structure
                $jobId = $video->id;
                
                $s3JobPath = "s3/jobs/{$jobId}";
                // Removing explicit makeDirectory for S3, as prefixes are created with object puts.
                // Storage::disk('s3')->makeDirectory($s3JobPath); 
                
                // Store the video with a standardized filename
                $videoFilename = "video.{$extension}";
                $fullS3Key = "{$s3JobPath}/{$videoFilename}"; // Explicit S3 key for the object

                // Get a stream from the uploaded file
                $fileStream = fopen($file->getRealPath(), 'r');

                if (!$fileStream) {
                    throw new \Exception("Failed to open stream for uploaded file: {$originalFilename}");
                }

                // Store the file stream to S3 using put()
                $result = Storage::disk('s3')->put(
                    $fullS3Key,
                    $fileStream,
                    ['ACL' => 'bucket-owner-full-control'] // Explicitly set ACL for bucket owner again
                );

                // Close the stream if it was opened
                if (is_resource($fileStream)) {
                    fclose($fileStream);
                }
                
                if (!$result) { // Storage::put() returns true on success, false on failure
                    throw new \Exception("Failed to store video file to S3 (using put method): {$originalFilename}");
                }
                
                // Update the video record with the S3 storage path (key)
                $video->update([
                    'storage_path' => $fullS3Key, // Use the explicit S3 key
                    's3_key' => $videoFilename, 
                    'status' => 'uploaded',
                ]);
                
                // Log success
                Log::info('Video file upload', [
                    'video_id' => $video->id,
                    'original_filename' => $originalFilename,
                    'storage_path' => $fullS3Key,
                    'file_size' => $file->getSize(),
                    'batch_index' => $index,
                ]);
                
                // Automatically start audio extraction
                $video->update(['status' => 'processing']);
                
                // Dispatch audio extraction job to queue
                \App\Jobs\AudioExtractionJob::dispatch($video);
                
                $createdVideos[] = $video;
                
            } catch (\Exception $e) {
                // If anything goes wrong, update the status to failed
                $video->update(['status' => 'failed']);
                
                Log::error('Video upload failed', [
                    'error' => $e->getMessage(),
                    'video_id' => $video->id,
                    'original_filename' => $originalFilename,
                    'batch_index' => $index,
                ]);
            }
        }
        
        // Determine the redirect based on number of videos and course
        if (count($createdVideos) === 1) {
            // If only one video, redirect to its detail page
            return redirect()->route('videos.show', $createdVideos[0])
                ->with('success', 'Video uploaded successfully and processing started.');
        } else if ($courseId) {
            // If multiple videos with a course, redirect to the course page
            return redirect()->route('courses.show', $courseId)
                ->with('success', count($createdVideos) . ' videos uploaded successfully to the course and processing started.');
        } else {
            // Otherwise redirect to the videos index
            return redirect()->route('videos.index')
                ->with('success', count($createdVideos) . ' videos uploaded successfully and processing started.');
        }
    }
    
    /**
     * Display the specified video.
     */
    public function show(Video $video)
    {
        // Load the course relationship if available
        if ($video->course_id) {
            $video->load('course');
            
            // Get next and previous lessons in the course
            $nextLesson = $video->nextLesson;
            $previousLesson = $video->previousLesson;
            
            return Inertia::render('Videos/Show', [
                'video' => $video,
                'course' => $video->course,
                'nextLesson' => $nextLesson,
                'previousLesson' => $previousLesson,
            ]);
        }
        
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
            
            // Check if the job directory exists on S3
            // Note: S3 doesn't have true directories. deleteDirectory will delete objects under the prefix.
            // We can check if any files exist under that prefix as a proxy for the directory existing.
            $filesInJobPath = Storage::disk('s3')->files($jobPath);

            if (!empty($filesInJobPath)) {
                // Delete the entire job directory (all objects under the prefix) from S3
                Storage::disk('s3')->deleteDirectory($jobPath);
                Log::info('Deleted job directory from S3 for video', [
                    'video_id' => $video->id,
                    'job_path' => $jobPath
                ]);
            } else {
                // Fallback to just deleting the video file if job directory doesn't exist or is empty
                // This check might be redundant if storage_path is always within jobPath
                if ($video->storage_path && Storage::disk('s3')->exists($video->storage_path)) {
                    Storage::disk('s3')->delete($video->storage_path);
                    Log::info('Deleted video file only from S3', [
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
            // Check if file exists on S3
            if (!$video->storage_path || !Storage::disk('s3')->exists($video->storage_path)) {
                Log::error('Video file not found on S3', [
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

    /**
     * Update video
     */
    public function update(Request $request, Video $video)
    {
        // ... existing code ...
    }

    /**
     * Generate a thumbnail for a video
     */
    public function requestThumbnail($id)
    {
        $video = Video::findOrFail($id);
        
        // If we already have a thumbnail, don't regenerate
        if ($video->thumbnail_path) {
            return response()->json(['message' => 'Thumbnail already exists', 'success' => true]);
        }
        
        // Queue the job to generate the thumbnail
        $videoKey = $video->s3_key;
        $outputKey = "jobs/{$video->id}/thumbnail.jpg";
        
        // Add a job to extract an image frame from the video
        ThumbnailExtractionJob::dispatch($video, $videoKey, $outputKey);
        
        return response()->json(['message' => 'Thumbnail generation requested', 'success' => true]);
    }

    /**
     * Callback endpoint for thumbnail generation
     */
    public function thumbnailCallback(Request $request, $id)
    {
        $video = Video::findOrFail($id);
        
        $status = $request->input('status');
        $thumbnailPath = $request->input('thumbnail_path');
        
        if ($status === 'completed' && $thumbnailPath) {
            $video->thumbnail_path = $thumbnailPath;
            $video->save();
            
            return response()->json(['message' => 'Thumbnail updated successfully', 'success' => true]);
        }
        
        return response()->json(['message' => 'Failed to generate thumbnail', 'success' => false], 500);
    }
}
