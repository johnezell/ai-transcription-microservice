<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Video;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
        
        return Inertia::render('Courses/Show', [
            'course' => $course,
            'unassignedVideos' => $unassignedVideos,
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