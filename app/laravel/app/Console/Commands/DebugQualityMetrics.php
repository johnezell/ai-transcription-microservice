<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TruefireSegmentProcessing;

class DebugQualityMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'debug:quality-metrics {course_id=1}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug quality metrics for a course';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $courseId = $this->argument('course_id');
        
        $this->info("=== Debug Quality Metrics for Course {$courseId} ===");
        
        // Check if we have completed segments
        $completedSegments = TruefireSegmentProcessing::where('course_id', $courseId)
            ->where('status', 'completed')
            ->get();

        $this->info("Completed segments for course {$courseId}: {$completedSegments->count()}");

        if ($completedSegments->count() > 0) {
            // Check first few segments
            $segmentsToCheck = $completedSegments->take(5);
            
            foreach ($segmentsToCheck as $segment) {
                $this->info("\n--- Segment {$segment->segment_id} ---");
                $this->info("JSON path: '" . ($segment->transcript_json_path ?? 'NULL') . "'");
                
                // Try to find JSON file in expected locations
                $expectedPaths = [
                    "/mnt/d_drive/truefire-courses/{$courseId}/{$segment->segment_id}_transcript.json",
                    "/var/www/html/storage/transcripts/{$segment->segment_id}_transcript.json",
                    "/tmp/transcripts/{$segment->segment_id}_transcript.json"
                ];
                
                foreach ($expectedPaths as $path) {
                    if (file_exists($path)) {
                        $this->info("Found JSON at: {$path}");
                        
                        // Try to load and analyze this file
                        $this->analyzeJsonFile($path);
                        break;
                    }
                }
            }
            
            // Also check the database fields for all completed segments
            $this->info("\n=== Database Analysis ===");
            $nonNullPaths = $completedSegments->whereNotNull('transcript_json_path')->count();
            $this->info("Segments with non-null transcript_json_path: {$nonNullPaths}");
            
            if ($nonNullPaths > 0) {
                $sampleWithPath = $completedSegments->whereNotNull('transcript_json_path')->first();
                $this->info("Sample path: {$sampleWithPath->transcript_json_path}");
                $this->info("Path exists: " . (file_exists($sampleWithPath->transcript_json_path) ? 'YES' : 'NO'));
                
                if (file_exists($sampleWithPath->transcript_json_path)) {
                    $this->analyzeJsonFile($sampleWithPath->transcript_json_path);
                }
            }
            
        } else {
            $this->warn("No completed segments found for course {$courseId}");
        }
        
        return Command::SUCCESS;
    }
    
    private function analyzeJsonFile($path)
    {
        $this->info("Analyzing JSON file: {$path}");
        
        $jsonContent = file_get_contents($path);
        $data = json_decode($jsonContent, true);
        
        if (!$data) {
            $this->error("Failed to decode JSON");
            return;
        }
        
        $this->info("=== JSON Structure Analysis ===");
        $this->info("Has segments: " . (isset($data['segments']) ? 'YES' : 'NO'));
        
        if (isset($data['segments']) && is_array($data['segments']) && count($data['segments']) > 0) {
            $firstSegment = $data['segments'][0];
            $this->info("First segment has words: " . (isset($firstSegment['words']) ? 'YES' : 'NO'));
            
            if (isset($firstSegment['words']) && is_array($firstSegment['words']) && count($firstSegment['words']) > 0) {
                $firstWord = $firstSegment['words'][0];
                $this->info("First word structure:");
                $this->info("  - word: " . ($firstWord['word'] ?? 'missing'));
                $this->info("  - probability: " . ($firstWord['probability'] ?? 'missing'));
                $this->info("  - score: " . ($firstWord['score'] ?? 'missing'));
                $this->info("  - confidence: " . ($firstWord['confidence'] ?? 'missing'));
                
                // Try to calculate confidence like our method does
                $confidence = $firstWord['probability'] ?? $firstWord['score'] ?? null;
                $this->info("  - calculated confidence: " . ($confidence ?? 'null'));
            }
        }
        
        $this->info("=== Quality Metrics Fields ===");
        $this->info("Has quality_metrics: " . (isset($data['quality_metrics']) ? 'YES' : 'NO'));
        $this->info("Has overall_quality_score: " . (isset($data['overall_quality_score']) ? 'YES' : 'NO'));
        $this->info("Has guitar_term_evaluation: " . (isset($data['guitar_term_evaluation']) ? 'YES' : 'NO'));
        
        if (isset($data['quality_metrics'])) {
            $this->info("Quality metrics keys: " . implode(', ', array_keys($data['quality_metrics'])));
            if (isset($data['quality_metrics']['overall_quality_score'])) {
                $this->info("Overall quality score: " . $data['quality_metrics']['overall_quality_score']);
            }
        }
        
        if (isset($data['guitar_term_evaluation'])) {
            $this->info("Guitar evaluation keys: " . implode(', ', array_keys($data['guitar_term_evaluation'])));
            if (isset($data['guitar_term_evaluation']['enhanced_terms'])) {
                $this->info("Enhanced terms count: " . count($data['guitar_term_evaluation']['enhanced_terms']));
            }
        }
        
        // Test our extraction methods
        $this->info("=== Testing Extraction Methods ===");
        $segmentConfidence = $this->extractSegmentConfidence($data);
        $this->info("Extracted confidence: " . ($segmentConfidence ?? 'null'));
        
        $segmentQuality = $this->extractSegmentQualityScore($data);
        $this->info("Extracted quality: " . ($segmentQuality ?? 'null'));
        
        $musicTerms = $this->extractMusicTermsCount($data);
        $this->info("Extracted music terms: " . $musicTerms);
    }
    
    private function extractSegmentConfidence($transcriptData)
    {
        if (!isset($transcriptData['segments']) || !is_array($transcriptData['segments'])) {
            return null;
        }
        
        $totalWords = 0;
        $confidenceSum = 0;
        
        foreach ($transcriptData['segments'] as $segment) {
            if (isset($segment['words']) && is_array($segment['words'])) {
                foreach ($segment['words'] as $word) {
                    $confidence = $word['probability'] ?? $word['score'] ?? null;
                    if ($confidence !== null) {
                        $confidenceSum += $confidence;
                        $totalWords++;
                    }
                }
            }
        }
        
        return $totalWords > 0 ? $confidenceSum / $totalWords : null;
    }
    
    private function extractSegmentQualityScore($transcriptData)
    {
        // Check for various quality score fields
        if (isset($transcriptData['quality_metrics']['overall_quality_score'])) {
            return $transcriptData['quality_metrics']['overall_quality_score'];
        }
        
        if (isset($transcriptData['overall_quality_score'])) {
            return $transcriptData['overall_quality_score'];
        }
        
        return null;
    }
    
    private function extractMusicTermsCount($transcriptData)
    {
        $count = 0;
        
        // Check guitar term evaluation
        if (isset($transcriptData['guitar_term_evaluation']['enhanced_terms'])) {
            $count += count($transcriptData['guitar_term_evaluation']['enhanced_terms']);
        }
        
        // Check content quality metrics
        if (isset($transcriptData['content_quality']['music_term_count'])) {
            $count += $transcriptData['content_quality']['music_term_count'];
        }
        
        return $count;
    }
}
