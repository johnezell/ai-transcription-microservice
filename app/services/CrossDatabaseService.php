<?php

namespace App\Services;

use App\Models\Channels\Channel;
use App\Models\Channels\Segment;
use App\Models\TrueFire\Course;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CrossDatabaseService
{
    /**
     * Run the exact example query from the specification
     * 
     * @return array
     */
    public function runExampleQuery()
    {
        return DB::select("
            SELECT c.id, s.video, s.id as segment_id
            FROM truefire.courses c 
            JOIN channels.channels ch ON ch.courseid = c.id 
            JOIN channels.segments s ON s.channel_id = ch.id
        ");
    }
    
    /**
     * Get segments by course ID using relationships
     * 
     * @param int $courseId
     * @return array
     */
    public function getSegmentsByCourseIdUsingRelationships($courseId)
    {
        $course = Course::findOrFail($courseId);
        $segments = [];
        
        foreach ($course->channels as $channel) {
            $channelSegments = $channel->segments;
            $segments = array_merge($segments, $channelSegments->toArray());
        }
        
        return $segments;
    }
    
    /**
     * Get segments by course ID using direct query
     * 
     * @param int $courseId
     * @return array
     */
    public function getSegmentsByCourseIdUsingQuery($courseId)
    {
        return DB::select("
            SELECT c.id as course_id, c.title as course_title, 
                   s.id as segment_id, s.title as segment_title, s.video
            FROM truefire.courses c 
            JOIN channels.channels ch ON ch.courseid = c.id 
            JOIN channels.segments s ON s.channel_id = ch.id
            WHERE c.id = ?
        ", [$courseId]);
    }
    
    /**
     * Get all courses with their channels and segments
     * 
     * @return array
     */
    public function getAllCoursesWithChannelsAndSegments()
    {
        return DB::select("
            SELECT c.id as course_id, c.title as course_title, 
                   ch.id as channel_id, ch.name as channel_name,
                   s.id as segment_id, s.title as segment_title, s.video
            FROM truefire.courses c 
            JOIN channels.channels ch ON ch.courseid = c.id 
            JOIN channels.segments s ON s.channel_id = ch.id
        ");
    }
    
    /**
     * Get a formatted result structure using Eloquent
     * 
     * @return array
     */
    public function getNestedStructureUsingEloquent()
    {
        $result = [];
        $courses = Course::with(['channels.segments'])->get();
        
        foreach ($courses as $course) {
            $courseData = [
                'id' => $course->id,
                'title' => $course->title,
                'channels' => []
            ];
            
            foreach ($course->channels as $channel) {
                $channelData = [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'segments' => []
                ];
                
                foreach ($channel->segments as $segment) {
                    $channelData['segments'][] = [
                        'id' => $segment->id,
                        'title' => $segment->title,
                        'video' => $segment->video
                    ];
                }
                
                $courseData['channels'][] = $channelData;
            }
            
            $result[] = $courseData;
        }
        
        return $result;
    }
} 