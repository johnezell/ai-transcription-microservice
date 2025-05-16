<?php

namespace App\Http\Controllers;

use App\Models\TrueFire\Course;
use App\Models\TrueFire\Lesson;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class TrueFireController extends Controller
{
    /**
     * Display a listing of the courses.
     */
    public function index()
    {
        try {
            $courses = Course::with('lessons')->get();
            return Inertia::render('TrueFire/Index', [
                'courses' => $courses
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching TrueFire courses: ' . $e->getMessage());
            return Inertia::render('TrueFire/Index', [
                'courses' => [],
                'error' => 'Unable to connect to TrueFire database. Please try again later.'
            ]);
        }
    }

    /**
     * Display the specified course with its lessons.
     */
    public function show($id)
    {
        try {
            $course = Course::with('lessons')->findOrFail($id);
            return Inertia::render('TrueFire/Show', [
                'course' => $course
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching TrueFire course: ' . $e->getMessage());
            return redirect()->route('truefire.index')->with('error', 'Course not found');
        }
    }

    /**
     * Import a lesson from TrueFire to the transcription system.
     */
    public function importLesson(Request $request, $lessonId)
    {
        try {
            $lesson = Lesson::findOrFail($lessonId);
            
            // Create a new Video record in our main database
            $video = new Video();
            $video->title = $lesson->title;
            $video->description = $lesson->description;
            $video->duration = $lesson->duration_minutes * 60; // Convert to seconds
            $video->status = 'pending';
            $video->source = 'truefire';
            $video->source_id = $lesson->id;
            $video->source_data = json_encode([
                'course_id' => $lesson->course_id,
                'course_title' => $lesson->course->title,
                'sequence_number' => $lesson->sequence_number,
            ]);
            
            // The video URL needs to be downloaded and stored in our S3 bucket
            // For now, we'll just save the URL (implementation depends on TrueFire's actual structure)
            $video->url = $lesson->video_url;
            
            $video->save();
            
            return redirect()->route('truefire.show', $lesson->course_id)
                ->with('success', "Lesson '{$lesson->title}' has been added to the transcription queue");
        } catch (\Exception $e) {
            Log::error('Error importing TrueFire lesson: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to import lesson. Please try again later.');
        }
    }

    /**
     * Display a selection interface for importing courses.
     */
    public function selection()
    {
        try {
            $courses = Course::with('lessons')->get();
            return Inertia::render('TrueFire/Selection', [
                'courses' => $courses
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching TrueFire selection: ' . $e->getMessage());
            return Inertia::render('TrueFire/Selection', [
                'courses' => [],
                'error' => 'Unable to connect to TrueFire database. Please try again later.'
            ]);
        }
    }

    /**
     * Import multiple lessons from TrueFire to the transcription system.
     */
    public function importLessonsBulk(Request $request)
    {
        $request->validate([
            'lessons' => 'required|array',
            'lessons.*' => 'required|integer'
        ]);

        $importCount = 0;
        $errors = [];

        foreach ($request->lessons as $lessonId) {
            try {
                $lesson = Lesson::findOrFail($lessonId);
                
                // Create a new Video record in our main database
                $video = new Video();
                $video->title = $lesson->title;
                $video->description = $lesson->description;
                $video->duration = $lesson->duration_minutes * 60; // Convert to seconds
                $video->status = 'pending';
                $video->source = 'truefire';
                $video->source_id = $lesson->id;
                $video->source_data = json_encode([
                    'course_id' => $lesson->course_id,
                    'course_title' => $lesson->course->title,
                    'sequence_number' => $lesson->sequence_number,
                ]);
                
                // The video URL needs to be downloaded and stored in our S3 bucket
                // For now, we'll just save the URL
                $video->url = $lesson->video_url;
                
                $video->save();
                $importCount++;
            } catch (\Exception $e) {
                Log::error('Error importing TrueFire lesson: ' . $e->getMessage());
                $errors[] = "Failed to import lesson ID {$lessonId}: {$e->getMessage()}";
            }
        }

        if ($importCount > 0) {
            $message = "{$importCount} lesson" . ($importCount > 1 ? 's' : '') . " imported successfully";
            if (count($errors) > 0) {
                $message .= ", but " . count($errors) . " failed.";
            }
            
            return redirect()->route('truefire.selection')
                ->with('success', $message);
        } else {
            return redirect()->route('truefire.selection')
                ->with('error', 'No lessons were imported. ' . implode(' ', $errors));
        }
    }
} 