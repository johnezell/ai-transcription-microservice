<?php

namespace App\Services;

use App\Models\TrueFire\Course;
use App\Models\TrueFire\Lesson;
use Illuminate\Support\Collection;

class MockDataService
{
    /**
     * Get mock TrueFire courses
     *
     * @return Collection
     */
    public function getMockCourses(): Collection
    {
        $courses = [];
        
        // Create 5 mock courses
        for ($i = 1; $i <= 5; $i++) {
            $course = new Course();
            $course->id = $i;
            $course->title = "Guitar Course $i";
            $course->description = "This is a sample description for the guitar course $i. It covers various techniques and styles for guitar players of all levels.";
            $course->instructor = "Guitar Instructor " . chr(64 + $i); // A, B, C, etc.
            $course->difficulty_level = $this->getRandomDifficulty();
            $course->category = $this->getRandomCategory();
            $course->published_at = now()->subDays(rand(1, 365));
            $course->duration_minutes = rand(30, 300);
            $course->thumbnail_url = "https://placehold.co/600x400?text=Course+$i";
            
            // Add mock lessons to this course
            $course->setRelation('lessons', $this->getMockLessons($i));
            
            $courses[] = $course;
        }
        
        return collect($courses);
    }
    
    /**
     * Get a single mock course by ID
     *
     * @param int $id
     * @return Course|null
     */
    public function getMockCourse(int $id): ?Course
    {
        $courses = $this->getMockCourses();
        return $courses->firstWhere('id', $id);
    }
    
    /**
     * Get mock lessons for a course
     *
     * @param int $courseId
     * @return Collection
     */
    public function getMockLessons(int $courseId): Collection
    {
        $lessons = [];
        
        // Create 4-10 random lessons
        $count = rand(4, 10);
        for ($i = 1; $i <= $count; $i++) {
            $lesson = new Lesson();
            $lesson->id = ($courseId * 100) + $i; // Create unique IDs
            $lesson->course_id = $courseId;
            $lesson->title = "Lesson $i: " . $this->getRandomLessonTitle();
            $lesson->description = "This lesson covers important techniques and concepts for guitar players. You'll learn step-by-step how to master these skills.";
            $lesson->sequence_number = $i;
            $lesson->duration_minutes = rand(5, 45);
            $lesson->video_url = "https://example.com/videos/course-$courseId/lesson-$i.mp4";
            $lesson->thumbnail_url = "https://placehold.co/400x225?text=Lesson+$i";
            $lesson->is_free_preview = $i === 1; // First lesson is free
            
            $lessons[] = $lesson;
        }
        
        return collect($lessons);
    }
    
    /**
     * Get a random difficulty level
     *
     * @return string
     */
    private function getRandomDifficulty(): string
    {
        $difficulties = ['Beginner', 'Intermediate', 'Advanced', 'All Levels'];
        return $difficulties[array_rand($difficulties)];
    }
    
    /**
     * Get a random category
     *
     * @return string
     */
    private function getRandomCategory(): string
    {
        $categories = ['Blues', 'Rock', 'Jazz', 'Country', 'Classical', 'Folk', 'Metal'];
        return $categories[array_rand($categories)];
    }
    
    /**
     * Get a random lesson title
     *
     * @return string
     */
    private function getRandomLessonTitle(): string
    {
        $titles = [
            'Basic Chord Progressions',
            'Fingerpicking Techniques',
            'Understanding Scales',
            'Guitar Solo Fundamentals',
            'Rhythm and Timing',
            'Barre Chords Masterclass',
            'Blues Improvisation',
            'Jazz Chord Voicings',
            'Alternate Picking',
            'Advanced Hammer-ons & Pull-offs'
        ];
        return $titles[array_rand($titles)];
    }
} 