<?php

namespace App\Http\Controllers;

use App\Models\Channels\Channel;
use App\Models\Channels\Segment;
use App\Models\TrueFire\Course;
use App\Services\CrossDatabaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ChannelController extends Controller
{
    /**
     * The Cross Database Service instance.
     */
    protected $crossDbService;

    /**
     * Create a new controller instance.
     *
     * @param CrossDatabaseService $crossDbService
     * @return void
     */
    public function __construct(CrossDatabaseService $crossDbService)
    {
        $this->crossDbService = $crossDbService;
    }

    /**
     * Display a listing of the channels.
     */
    public function index()
    {
        try {
            $channels = Channel::with('course')->get();
            return Inertia::render('Channels/Index', [
                'channels' => $channels
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching channels: ' . $e->getMessage());
            return Inertia::render('Channels/Index', [
                'channels' => [],
                'error' => 'Unable to connect to channels database. Please try again later.'
            ]);
        }
    }

    /**
     * Display the specified channel with its segments.
     */
    public function show($id)
    {
        try {
            $channel = Channel::with(['segments', 'course'])->findOrFail($id);
            return Inertia::render('Channels/Show', [
                'channel' => $channel
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching channel: ' . $e->getMessage());
            return redirect()->route('channels.index')->with('error', 'Channel not found');
        }
    }

    /**
     * Get segments from a course using the cross-database join.
     */
    public function getCourseSegments($courseId)
    {
        try {
            $segments = $this->crossDbService->getSegmentsByCourseIdUsingRelationships($courseId);
            return response()->json([
                'success' => true,
                'data' => $segments
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching course segments via relationships: ' . $e->getMessage());
            
            // Fall back to raw query if relationships fail
            try {
                $results = $this->crossDbService->getSegmentsByCourseIdUsingQuery($courseId);
                return response()->json([
                    'success' => true,
                    'data' => $results
                ]);
            } catch (\Exception $e2) {
                Log::error('Error fetching course segments via raw query: ' . $e2->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to retrieve segments'
                ], 500);
            }
        }
    }

    /**
     * Get all cross-database joined data.
     */
    public function getAllCoursesWithSegments()
    {
        try {
            $results = $this->crossDbService->getAllCoursesWithChannelsAndSegments();
            return response()->json([
                'success' => true,
                'data' => $results
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching all courses with segments: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve data'
            ], 500);
        }
    }

    /**
     * Run the exact example query and return results.
     */
    public function runExampleQuery()
    {
        try {
            $results = $this->crossDbService->runExampleQuery();
            return response()->json([
                'success' => true,
                'data' => $results
            ]);
        } catch (\Exception $e) {
            Log::error('Error running example query: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to execute query: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get nested structure of courses, channels and segments.
     */
    public function getNestedStructure()
    {
        try {
            $results = $this->crossDbService->getNestedStructureUsingEloquent();
            return response()->json([
                'success' => true,
                'data' => $results
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting nested structure: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve nested structure'
            ], 500);
        }
    }

    /**
     * Import segments from channels database to the transcription system.
     */
    public function importSegment(Request $request, $segmentId)
    {
        try {
            $segment = Segment::with('channel.course')->findOrFail($segmentId);
            
            // Create a new Video record in our main database
            $video = new \App\Models\Video();
            $video->title = $segment->title;
            $video->description = $segment->description;
            $video->duration = $segment->duration ?: 0;
            $video->status = 'pending';
            $video->source = 'channel';
            $video->source_id = $segment->id;
            $video->source_data = json_encode([
                'channel_id' => $segment->channel_id,
                'channel_name' => $segment->channel->name,
                'course_id' => $segment->channel->courseid,
                'course_title' => $segment->channel->course->title ?? 'Unknown',
                'sequence' => $segment->sequence,
            ]);
            
            // The video URL needs to be downloaded and stored in our S3 bucket
            // For now, we'll just save the URL
            $video->url = $segment->video;
            
            $video->save();
            
            return redirect()->back()
                ->with('success', "Segment '{$segment->title}' has been added to the transcription queue");
        } catch (\Exception $e) {
            Log::error('Error importing segment: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to import segment: ' . $e->getMessage());
        }
    }
} 