<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Video;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CourseController extends Controller
{
    /**
     * Display a listing of the courses.
     */
    public function index()
    {
        $courses = Course::withCount('videos')->latest()->get();
        
        return Inertia::render('Courses/Index', [
            'courses' => $courses,
        ]);
    }
    
    /**
     * Show the form for creating a new course.
     */
    public function create()
    {
        return Inertia::render('Courses/Create');
    }
    
    /**
     * Store a newly created course.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'subject_area' => 'nullable|string|max:255',
        ]);
        
        $course = Course::create($validated);
        
        return redirect()->route('courses.show', $course)
            ->with('success', 'Course created successfully.');
    }
    
    /**
     * Display the specified course.
     */
    public function show(Course $course)
    {
        // Load the course with its videos
        $course->load(['videos' => function($query) {
            $query->orderBy('lesson_number', 'asc');
        }]);
        
        // Get unassigned videos for the add video modal
        $unassignedVideos = Video::whereNull('course_id')->get();
        
        // Get segments for testing - load a sample of segments from TrueFire database
        $segments = [];
        try {
            $segments = \App\Models\Segment::limit(10)->get()->map(function($segment) {
                try {
                    return [
                        'id' => $segment->id,
                        'video' => $segment->video,
                        'title' => $segment->title ?? 'Segment ' . $segment->id,
                        'signed_url' => $segment->getSignedUrl(),
                    ];
                } catch (\Exception $e) {
                    Log::warning('Failed to generate signed URL for segment', [
                        'segment_id' => $segment->id,
                        'error' => $e->getMessage()
                    ]);
                    return [
                        'id' => $segment->id,
                        'video' => $segment->video,
                        'title' => $segment->title ?? 'Segment ' . $segment->id,
                        'signed_url' => null,
                        'error' => 'Failed to generate signed URL'
                    ];
                }
            });
        } catch (\Exception $e) {
            Log::warning('Failed to load segments for testing', [
                'course_id' => $course->id,
                'error' => $e->getMessage()
            ]);
        }
        
        return Inertia::render('Courses/Show', [
            'course' => $course,
            'unassignedVideos' => $unassignedVideos,
            'segments' => $segments,
        ]);
    }
    
    /**
     * Show the form for editing the course.
     */
    public function edit(Course $course)
    {
        return Inertia::render('Courses/Edit', [
            'course' => $course,
        ]);
    }
    
    /**
     * Update the specified course.
     */
    public function update(Request $request, Course $course)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'subject_area' => 'nullable|string|max:255',
        ]);
        
        $course->update($validated);
        
        return redirect()->route('courses.show', $course)
            ->with('success', 'Course updated successfully.');
    }
    
    /**
     * Remove the specified course from storage.
     */
    public function destroy(Course $course)
    {
        // Remove course_id from all associated videos but keep the videos
        $course->videos()->update(['course_id' => null, 'lesson_number' => null]);
        
        // Delete the course
        $course->delete();
        
        return redirect()->route('courses.index')
            ->with('success', 'Course deleted successfully. Associated videos were preserved.');
    }
    
    /**
     * Remove the specified course and all its videos from storage.
     */
    public function destroyWithVideos(Request $request, Course $course)
    {
        // Get all videos associated with this course
        $videos = $course->videos()->get();
        $videoCount = $videos->count();
        
        // Delete each video and its files
        foreach ($videos as $video) {
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
                
                // Delete the video record
                $video->delete();
                
            } catch (\Exception $e) {
                Log::error('Error deleting video during course deletion', [
                    'video_id' => $video->id,
                    'course_id' => $course->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Delete the course
        $course->delete();
        
        return redirect()->route('courses.index')
            ->with('success', "Course and all {$videoCount} videos deleted successfully.");
    }
    
    /**
     * Add a video to the course.
     */
    public function addVideo(Request $request, Course $course)
    {
        $validated = $request->validate([
            'video_id' => 'required|exists:videos,id',
            'lesson_number' => 'required|integer|min:1',
        ]);
        
        $video = Video::find($validated['video_id']);
        $video->update([
            'course_id' => $course->id,
            'lesson_number' => $validated['lesson_number'],
        ]);
        
        return redirect()->route('courses.show', $course)
            ->with('success', 'Video added to course successfully.');
    }
    
    /**
     * Remove a video from the course.
     */
    public function removeVideo(Request $request, Course $course)
    {
        $validated = $request->validate([
            'video_id' => 'required|exists:videos,id',
        ]);
        
        $video = Video::where('id', $validated['video_id'])
            ->where('course_id', $course->id)
            ->firstOrFail();
            
        $video->update([
            'course_id' => null,
            'lesson_number' => null,
        ]);
        
        return redirect()->route('courses.show', $course)
            ->with('success', 'Video removed from course successfully.');
    }
    
    /**
     * Update video lesson numbers/order in the course.
     */
    public function updateVideoOrder(Request $request, Course $course)
    {
        $validated = $request->validate([
            'videos' => 'required|array',
            'videos.*.id' => 'required|exists:videos,id',
            'videos.*.lesson_number' => 'required|integer|min:1',
        ]);
        
        foreach ($validated['videos'] as $videoData) {
            $video = Video::where('id', $videoData['id'])
                ->where('course_id', $course->id)
                ->first();
                
            if ($video) {
                $video->update([
                    'lesson_number' => $videoData['lesson_number'],
                ]);
            }
        }
        
        return redirect()->route('courses.show', $course)
            ->with('success', 'Video order updated successfully.');
    }
    
    /**
     * Show cross-analysis view for the course.
     */
    public function analysis(Course $course)
    {
        // Load the course with its videos
        $course->load(['videos' => function($query) {
            $query->orderBy('lesson_number', 'asc');
        }]);
        
        // Get terminology from all videos in the course
        $terminologies = [];
        foreach ($course->videos as $video) {
            $terminology = $video->getTerminologyJsonDataAttribute();
            if ($terminology) {
                $terminologies[$video->id] = [
                    'video_id' => $video->id,
                    'lesson_number' => $video->lesson_number,
                    'title' => $video->lesson_title,
                    'terminology' => $terminology,
                ];
            }
        }
        
        return Inertia::render('Courses/Analysis', [
            'course' => $course,
            'terminologies' => $terminologies,
        ]);
    }
} 