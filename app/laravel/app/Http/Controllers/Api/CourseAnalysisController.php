<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Video;
use Illuminate\Http\Request;

class CourseAnalysisController extends Controller
{
    /**
     * Get all terminology data for a course.
     */
    public function getTerminology(Course $course)
    {
        $videos = $course->videos()
            ->orderBy('lesson_number', 'asc')
            ->get();
            
        $result = [];
        
        foreach ($videos as $video) {
            $terminology = $video->getTerminologyJsonDataAttribute();
            
            if ($terminology) {
                $result[] = [
                    'video_id' => $video->id,
                    'lesson_number' => $video->lesson_number,
                    'title' => $video->lesson_title,
                    'terminology' => $terminology,
                    'transcript_url' => $video->transcript_json_api_url,
                ];
            }
        }
        
        return response()->json($result);
    }
    
    /**
     * Get frequency analysis of terminology across the course.
     */
    public function getTerminologyFrequency(Course $course)
    {
        $videos = $course->videos()
            ->orderBy('lesson_number', 'asc')
            ->get();
            
        $termFrequency = [];
        $videoData = [];
        
        foreach ($videos as $video) {
            $terminology = $video->getTerminologyJsonDataAttribute();
            
            if (!$terminology) {
                continue;
            }
            
            $videoData[$video->id] = [
                'id' => $video->id,
                'lesson_number' => $video->lesson_number,
                'title' => $video->lesson_title,
            ];
            
            foreach ($terminology as $term) {
                $termText = $term['text'] ?? '';
                
                if (!$termText) {
                    continue;
                }
                
                if (!isset($termFrequency[$termText])) {
                    $termFrequency[$termText] = [
                        'term' => $termText,
                        'category' => $term['category'] ?? 'Uncategorized',
                        'total_occurrences' => 0,
                        'videos' => [],
                    ];
                }
                
                // Count occurrences in this video
                if (!isset($termFrequency[$termText]['videos'][$video->id])) {
                    $termFrequency[$termText]['videos'][$video->id] = 0;
                }
                
                $termFrequency[$termText]['videos'][$video->id]++;
                $termFrequency[$termText]['total_occurrences']++;
            }
        }
        
        // Convert videos associative arrays to sequential arrays for easier frontend processing
        foreach ($termFrequency as &$term) {
            $videoOccurrences = [];
            
            foreach ($videoData as $videoId => $video) {
                $videoOccurrences[] = [
                    'video_id' => $videoId,
                    'lesson_number' => $video['lesson_number'],
                    'title' => $video['title'],
                    'occurrences' => $term['videos'][$videoId] ?? 0,
                ];
            }
            
            $term['videos'] = $videoOccurrences;
        }
        
        // Sort by total occurrences (most frequent first)
        usort($termFrequency, function($a, $b) {
            return $b['total_occurrences'] - $a['total_occurrences'];
        });
        
        return response()->json([
            'terms' => array_values($termFrequency),
            'videos' => array_values($videoData),
        ]);
    }
    
    /**
     * Get combined transcripts of all videos in a course.
     */
    public function getCombinedTranscripts(Course $course)
    {
        $videos = $course->videos()
            ->orderBy('lesson_number', 'asc')
            ->get();
            
        $result = [];
        
        foreach ($videos as $video) {
            $transcriptData = $video->getTranscriptJsonDataAttribute();
            
            if ($transcriptData) {
                $result[] = [
                    'video_id' => $video->id,
                    'lesson_number' => $video->lesson_number,
                    'title' => $video->lesson_title,
                    'transcript' => $transcriptData,
                ];
            }
        }
        
        return response()->json($result);
    }
    
    /**
     * Search across all transcripts in a course.
     */
    public function searchTranscripts(Request $request, Course $course)
    {
        $request->validate([
            'query' => 'required|string|min:2',
        ]);
        
        $searchQuery = $request->input('query');
        $videos = $course->videos()->get();
        
        $results = [];
        
        foreach ($videos as $video) {
            $transcriptData = $video->getTranscriptJsonDataAttribute();
            
            if (!$transcriptData) {
                continue;
            }
            
            $segments = $transcriptData['segments'] ?? [];
            $matches = [];
            
            foreach ($segments as $segment) {
                $text = $segment['text'] ?? '';
                
                if (stripos($text, $searchQuery) !== false) {
                    $matches[] = [
                        'segment_id' => $segment['id'] ?? null,
                        'start_time' => $segment['start_time'] ?? 0,
                        'end_time' => $segment['end_time'] ?? 0,
                        'text' => $text,
                    ];
                }
            }
            
            if (count($matches) > 0) {
                $results[] = [
                    'video_id' => $video->id,
                    'lesson_number' => $video->lesson_number,
                    'title' => $video->lesson_title,
                    'matches' => $matches,
                    'match_count' => count($matches),
                ];
            }
        }
        
        // Sort by match count
        usort($results, function($a, $b) {
            return $b['match_count'] - $a['match_count'];
        });
        
        return response()->json([
            'query' => $searchQuery,
            'course' => [
                'id' => $course->id,
                'name' => $course->name,
            ],
            'results' => $results,
            'total_matches' => count($results),
        ]);
    }
} 