<?php

namespace App\Http\Controllers;

use App\Models\Video;
use App\Models\Course;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Display the dashboard page.
     */
    public function index()
    {
        // Get statistics
        $stats = [
            'totalVideos' => Video::count(),
            'processingVideos' => Video::whereIn('status', ['processing', 'is_processing', 'uploaded'])->count(),
            'completedVideos' => Video::where('status', 'completed')->count(),
            'totalCourses' => Course::count(),
        ];
        
        // Get recent videos
        $recentVideos = Video::with('course')
            ->latest()
            ->take(5)
            ->get();
        
        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentVideos' => $recentVideos,
        ]);
    }
} 