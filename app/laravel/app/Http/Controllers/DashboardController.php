<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Video;
use App\Models\TrueFire\TrueFireCourse;
use App\Models\TrueFire\TrueFireLesson;
use App\Models\TrueFire\TrueFireEducator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Check if TrueFire database connection is available
     * 
     * Note: TrueFire DB requires VPC peering to be accessible from ECS.
     * Set TRUEFIRE_DB_ENABLED=true in environment once network is configured.
     */
    private function isTrueFireConnectionAvailable(): bool
    {
        // TrueFire connection must be explicitly enabled via environment variable
        // This prevents hanging connections when VPC peering is not set up
        if (!env('TRUEFIRE_DB_ENABLED', false)) {
            return false;
        }
        
        try {
            DB::connection('truefire')->select('SELECT 1');
            return true;
        } catch (\Exception $e) {
            Log::warning('TrueFire database connection unavailable: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get courses from TrueFire database
     */
    private function getTrueFireCourses(Request $request): array
    {
        try {
            $query = TrueFireCourse::query()
                ->select([
                    'courses.id',
                    'courses.title',
                    'courses.description',
                    'courses.slug',
                    'courses.educator_id',
                    'courses.created_at',
                ])
                ->withCount('lessons');
            
            // Join educator for instructor name
            $query->leftJoin('educators', 'courses.educator_id', '=', 'educators.id')
                  ->addSelect('educators.name as instructor_name');
            
            // Apply search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('courses.title', 'like', "%{$search}%")
                      ->orWhere('educators.name', 'like', "%{$search}%");
                });
            }
            
            // Apply instructor filter
            if ($request->has('instructor') && $request->instructor) {
                $query->where('educators.name', $request->instructor);
            }
            
            // Get total count before pagination
            $total = $query->count();
            
            // Pagination
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 50);
            
            $courses = $query->orderBy('courses.title')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();
            
            // Transform to array format expected by frontend
            $coursesArray = $courses->map(function($course) {
                $lessonCount = $course->lessons_count ?? 0;
                // For now, mark all as pending since we haven't transcribed yet
                return [
                    'id' => $course->id,
                    'truefire_id' => 'TF' . str_pad($course->id, 6, '0', STR_PAD_LEFT),
                    'name' => $course->title,
                    'instructor' => $course->instructor_name ?? 'Unknown',
                    'genre' => 'Guitar', // TODO: Pull from course metadata
                    'level' => 'Intermediate', // TODO: Pull from course metadata
                    'description' => $course->description,
                    'video_count' => $lessonCount,
                    'transcribed_count' => 0, // TODO: Track in local DB
                    'processing_count' => 0,
                    'pending_count' => $lessonCount,
                    'failed_count' => 0,
                    'status' => 'pending',
                    'progress' => 0,
                    'created_at' => $course->created_at,
                    'last_processed_at' => null,
                ];
            })->toArray();
            
            // Get unique instructors for filter dropdown
            $instructors = TrueFireCourse::query()
                ->leftJoin('educators', 'courses.educator_id', '=', 'educators.id')
                ->select('educators.name')
                ->distinct()
                ->whereNotNull('educators.name')
                ->orderBy('educators.name')
                ->pluck('name')
                ->toArray();
            
            return [
                'courses' => $coursesArray,
                'total' => $total,
                'instructors' => $instructors,
                'source' => 'truefire',
            ];
        } catch (\Exception $e) {
            Log::error('Error fetching TrueFire courses: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Generate mock TrueFire course data
     * Used as fallback when TrueFire database is not accessible
     */
    private function generateMockCourses(int $count = 1000): array
    {
        $instructors = [
            'Tommy Emmanuel', 'Joe Bonamassa', 'Steve Vai', 'John Petrucci',
            'Guthrie Govan', 'Andy Wood', 'Robben Ford', 'Larry Carlton',
            'Jeff Beck', 'Eric Johnson', 'Oz Noy', 'Carl Verheyen',
            'Frank Vignola', 'Martin Taylor', 'Julian Lage', 'Pasquale Grasso',
            'Mike Stern', 'Pat Martino', 'Joscho Stephan', 'Andreas Oberg'
        ];
        
        $genres = [
            'Blues', 'Jazz', 'Rock', 'Country', 'Fingerstyle', 'Classical',
            'Metal', 'Funk', 'R&B', 'Acoustic', 'Slide Guitar', 'Gypsy Jazz',
            'Bluegrass', 'Americana', 'Fusion', 'Progressive'
        ];
        
        $levels = ['Beginner', 'Intermediate', 'Advanced', 'Master Class'];
        
        $courseTypes = [
            'Essentials', 'Masterclass', 'Method', 'Techniques', 'Licks',
            'Rhythms', 'Solos', 'Theory', 'Improvisation', 'Performance'
        ];
        
        $courses = [];
        
        for ($i = 1; $i <= $count; $i++) {
            $instructor = $instructors[array_rand($instructors)];
            $genre = $genres[array_rand($genres)];
            $level = $levels[array_rand($levels)];
            $type = $courseTypes[array_rand($courseTypes)];
            
            $videoCount = rand(8, 45);
            $transcribedCount = rand(0, $videoCount);
            $processingCount = $transcribedCount < $videoCount ? rand(0, min(3, $videoCount - $transcribedCount)) : 0;
            $pendingCount = $videoCount - $transcribedCount - $processingCount;
            $failedCount = rand(0, 2);
            
            // Determine overall status
            $status = 'pending';
            if ($transcribedCount === $videoCount) {
                $status = 'completed';
            } elseif ($processingCount > 0) {
                $status = 'processing';
            } elseif ($transcribedCount > 0) {
                $status = 'partial';
            }
            
            $courses[] = [
                'id' => $i,
                'truefire_id' => 'TF' . str_pad($i, 6, '0', STR_PAD_LEFT),
                'name' => "{$instructor}'s {$genre} {$type}",
                'instructor' => $instructor,
                'genre' => $genre,
                'level' => $level,
                'description' => "Master {$genre} guitar with {$instructor} in this comprehensive {$level} course.",
                'video_count' => $videoCount,
                'transcribed_count' => $transcribedCount,
                'processing_count' => $processingCount,
                'pending_count' => $pendingCount,
                'failed_count' => $failedCount,
                'status' => $status,
                'progress' => $videoCount > 0 ? round(($transcribedCount / $videoCount) * 100) : 0,
                'created_at' => now()->subDays(rand(1, 365))->toIso8601String(),
                'last_processed_at' => $transcribedCount > 0 ? now()->subHours(rand(1, 168))->toIso8601String() : null,
            ];
        }
        
        return $courses;
    }
    
    /**
     * Display the main dashboard.
     */
    public function index(Request $request)
    {
        $dataSource = 'mock';
        $allCourses = [];
        $instructors = [];
        $total = 0;
        
        // Try TrueFire database first
        if ($this->isTrueFireConnectionAvailable()) {
            try {
                $trueFireData = $this->getTrueFireCourses($request);
                $allCourses = $trueFireData['courses'];
                $instructors = $trueFireData['instructors'];
                $total = $trueFireData['total'];
                $dataSource = 'truefire';
            } catch (\Exception $e) {
                Log::warning('Falling back to mock data: ' . $e->getMessage());
            }
        }
        
        // Fall back to mock data if TrueFire unavailable
        if (empty($allCourses)) {
            $mockCourses = $this->generateMockCourses(1000);
            
            // Apply filters to mock data
            $filteredCourses = $mockCourses;
            
            if ($request->has('search') && $request->search) {
                $search = strtolower($request->search);
                $filteredCourses = array_filter($filteredCourses, function($course) use ($search) {
                    return str_contains(strtolower($course['name']), $search) ||
                           str_contains(strtolower($course['instructor']), $search);
                });
            }
            
            if ($request->has('genre') && $request->genre) {
                $filteredCourses = array_filter($filteredCourses, fn($c) => $c['genre'] === $request->genre);
            }
            
            if ($request->has('level') && $request->level) {
                $filteredCourses = array_filter($filteredCourses, fn($c) => $c['level'] === $request->level);
            }
            
            if ($request->has('status') && $request->status) {
                $filteredCourses = array_filter($filteredCourses, fn($c) => $c['status'] === $request->status);
            }
            
            if ($request->has('instructor') && $request->instructor) {
                $filteredCourses = array_filter($filteredCourses, fn($c) => $c['instructor'] === $request->instructor);
            }
            
            // Pagination for mock data
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 50);
            $total = count($filteredCourses);
            $allCourses = array_slice(array_values($filteredCourses), ($page - 1) * $perPage, $perPage);
            
            // Get unique values for filters from mock data
            $instructors = array_values(array_unique(array_column($mockCourses, 'instructor')));
            sort($instructors);
        }
        
        // Calculate stats from filtered courses (for mock) or estimate from total (for TrueFire)
        if ($dataSource === 'truefire') {
            // Get total lesson count across all courses
            $totalVideos = TrueFireLesson::count();
            $stats = [
                'total_courses' => $total,
                'total_videos' => $totalVideos,
                'transcribed_videos' => 0, // TODO: Track locally
                'processing_videos' => 0,
                'pending_videos' => $totalVideos,
                'failed_videos' => 0,
                'completion_percentage' => 0,
                'courses_completed' => 0,
                'courses_processing' => 0,
                'courses_partial' => 0,
                'courses_pending' => $total,
            ];
        } else {
            $mockCourses = $this->generateMockCourses(1000);
            $totalVideos = array_sum(array_column($mockCourses, 'video_count'));
            $transcribedVideos = array_sum(array_column($mockCourses, 'transcribed_count'));
            $processingVideos = array_sum(array_column($mockCourses, 'processing_count'));
            $pendingVideos = array_sum(array_column($mockCourses, 'pending_count'));
            $failedVideos = array_sum(array_column($mockCourses, 'failed_count'));
            
            $stats = [
                'total_courses' => count($mockCourses),
                'total_videos' => $totalVideos,
                'transcribed_videos' => $transcribedVideos,
                'processing_videos' => $processingVideos,
                'pending_videos' => $pendingVideos,
                'failed_videos' => $failedVideos,
                'completion_percentage' => $totalVideos > 0 ? round(($transcribedVideos / $totalVideos) * 100, 1) : 0,
                'courses_completed' => count(array_filter($mockCourses, fn($c) => $c['status'] === 'completed')),
                'courses_processing' => count(array_filter($mockCourses, fn($c) => $c['status'] === 'processing')),
                'courses_partial' => count(array_filter($mockCourses, fn($c) => $c['status'] === 'partial')),
                'courses_pending' => count(array_filter($mockCourses, fn($c) => $c['status'] === 'pending')),
            ];
        }
        
        // Simulate job queue stats (TODO: connect to real queue)
        $jobQueue = [
            'active_jobs' => rand(0, 5),
            'queued_jobs' => rand(0, 50),
            'completed_today' => rand(50, 200),
            'failed_today' => rand(0, 5),
            'avg_processing_time' => rand(45, 180),
            'estimated_completion' => now()->addHours(rand(2, 48))->toIso8601String(),
        ];
        
        // Get unique values for filters
        $genres = ['Blues', 'Jazz', 'Rock', 'Country', 'Fingerstyle', 'Classical', 'Metal', 'Funk', 'Acoustic'];
        $levels = ['Beginner', 'Intermediate', 'Advanced', 'Master Class'];
        
        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 50);
        
        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'jobQueue' => $jobQueue,
            'courses' => $allCourses,
            'filters' => [
                'genres' => $genres,
                'instructors' => $instructors,
                'levels' => $levels,
                'statuses' => ['pending', 'processing', 'partial', 'completed'],
            ],
            'currentFilters' => [
                'search' => $request->search,
                'genre' => $request->genre,
                'level' => $request->level,
                'status' => $request->status,
                'instructor' => $request->instructor,
            ],
            'pagination' => [
                'current_page' => (int) $page,
                'per_page' => (int) $perPage,
                'total' => $total,
                'last_page' => max(1, ceil($total / $perPage)),
            ],
            'dataSource' => $dataSource, // Let frontend know data source
        ]);
    }
    
    /**
     * Start bulk transcription for selected courses.
     */
    public function startBulkTranscription(Request $request)
    {
        $validated = $request->validate([
            'course_ids' => 'required|array|min:1',
            'course_ids.*' => 'integer',
        ]);
        
        // TODO: Implement actual job dispatch logic
        // For now, return a mock response
        $jobCount = count($validated['course_ids']) * rand(10, 30); // Simulate video count
        
        return response()->json([
            'success' => true,
            'message' => "Queued {$jobCount} videos for transcription from " . count($validated['course_ids']) . " courses.",
            'jobs_queued' => $jobCount,
        ]);
    }
    
    /**
     * Get real-time queue status.
     */
    public function queueStatus()
    {
        // TODO: Implement actual queue status check
        return response()->json([
            'active_jobs' => rand(0, 5),
            'queued_jobs' => rand(0, 50),
            'completed_today' => rand(50, 200),
            'failed_today' => rand(0, 5),
            'avg_processing_time' => rand(45, 180),
            'workers_active' => rand(1, 3),
        ]);
    }
}

