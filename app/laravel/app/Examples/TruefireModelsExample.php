<?php

namespace App\Examples;

use App\Models\TruefireCourse;
use App\Models\Channel;
use App\Models\Segment;

/**
 * Example usage of the Truefire models
 */
class TruefireModelsExample
{
    /**
     * Example: Get all courses with their channels and segments
     */
    public function getAllCoursesWithRelations()
    {
        return TruefireCourse::with(['channels.segments'])->get();
    }

    /**
     * Example: Get a specific course by ID with channels and segments
     */
    public function getCourseWithRelations($courseId)
    {
        return TruefireCourse::with(['channels.segments'])
            ->find($courseId);
    }

    /**
     * Example: Get all channels for a specific course
     */
    public function getChannelsForCourse($courseId)
    {
        return Channel::where('courseid', $courseId)
            ->with('segments')
            ->get();
    }

    /**
     * Example: Get all segments for a specific channel
     */
    public function getSegmentsForChannel($channelId)
    {
        return Segment::where('channel_id', $channelId)
            ->with('channel.course')
            ->get();
    }

    /**
     * Example: Replicate the SQL query from the task
     * SELECT s.id,c.id,ch.id FROM channels.segments s 
     * JOIN channels.channels ch ON s.channel_id=ch.id 
     * JOIN truefire.courses c on ch.courseid=c.id
     */
    public function replicateExampleQuery()
    {
        return Segment::select('segments.id as segment_id', 'channels.id as channel_id', 'courses.id as course_id')
            ->join('channels.channels as channels', 'segments.channel_id', '=', 'channels.id')
            ->join('truefire.courses as courses', 'channels.courseid', '=', 'courses.id')
            ->get();
    }

    /**
     * Example: Using Eloquent relationships (preferred approach)
     */
    public function getSegmentsWithCourseAndChannelInfo()
    {
        return Segment::with(['channel', 'course'])
            ->get()
            ->map(function ($segment) {
                return [
                    'segment_id' => $segment->id,
                    'channel_id' => $segment->channel->id,
                    'course_id' => $segment->course->id,
                    'segment' => $segment,
                    'channel' => $segment->channel,
                    'course' => $segment->course,
                ];
            });
    }
}