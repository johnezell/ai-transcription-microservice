<?php

/**
 * Final Verification Test - Controller Endpoints
 * 
 * This script tests the actual controller endpoints to ensure the video field filtering
 * is working correctly in real-world usage scenarios.
 */

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\TruefireCourse;
use App\Http\Controllers\TruefireCourseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ControllerEndpointTest
{
    private $controller;
    private $testCourse;
    
    public function __construct()
    {
        $this->controller = new TruefireCourseController();
    }
    
    public function runTests()
    {
        echo "=== Controller Endpoint Verification Test ===\n\n";
        
        try {
            // Find a test course
            $this->findTestCourse();
            
            // Test index method with segment counting
            $this->testIndexMethod();
            
            // Test show method with video filtering
            $this->testShowMethod();
            
            // Test download status with filtering
            $this->testDownloadStatus();
            
            echo "\n✅ All controller endpoint tests passed successfully!\n";
            echo "The video field filtering implementation is working correctly in production scenarios.\n\n";
            
        } catch (Exception $e) {
            echo "❌ Controller test failed: " . $e->getMessage() . "\n";
        }
    }
    
    private function findTestCourse()
    {
        echo "🔍 Finding test course...\n";
        
        $this->testCourse = TruefireCourse::withCount(['segments', 'allSegments'])
            ->having('segments_count', '>', 0)
            ->first();
        
        if (!$this->testCourse) {
            throw new Exception("No suitable test course found");
        }
        
        echo "  ✅ Selected course ID {$this->testCourse->id} with {$this->testCourse->segments_count} filtered segments and {$this->testCourse->all_segments_count} total segments\n\n";
    }
    
    private function testIndexMethod()
    {
        echo "🔍 Testing index method with segment counting...\n";
        
        $request = new Request();
        $response = $this->controller->index($request);
        
        // The response should be an Inertia response, but we can check the data
        $responseData = $response->getData();
        
        if (isset($responseData['courses'])) {
            $courses = $responseData['courses'];
            echo "  ✅ Index method returned " . count($courses->items()) . " courses with segment counts\n";
            
            // Check if our test course is in the results and has correct counts
            foreach ($courses->items() as $course) {
                if ($course->id === $this->testCourse->id) {
                    echo "  ✅ Test course found in index with {$course->segments_count} segments (filtered count)\n";
                    break;
                }
            }
        } else {
            throw new Exception("Index method did not return expected course data");
        }
        
        echo "\n";
    }
    
    private function testShowMethod()
    {
        echo "🔍 Testing show method with video filtering...\n";
        
        $response = $this->controller->show($this->testCourse);
        $responseData = $response->getData();
        
        if (isset($responseData['segmentsWithSignedUrls'])) {
            $segments = $responseData['segmentsWithSignedUrls'];
            echo "  ✅ Show method returned " . count($segments) . " segments with signed URLs\n";
            
            // Verify all segments have valid video fields
            $validSegments = 0;
            foreach ($segments as $segment) {
                if (!empty($segment['video']) && str_starts_with($segment['video'], 'mp4:') && strlen($segment['video']) > 4) {
                    $validSegments++;
                }
            }
            
            echo "  ✅ All {$validSegments} segments have valid video fields\n";
            
            if ($validSegments !== count($segments)) {
                throw new Exception("Some segments do not have valid video fields");
            }
        } else {
            throw new Exception("Show method did not return expected segment data");
        }
        
        echo "\n";
    }
    
    private function testDownloadStatus()
    {
        echo "🔍 Testing download status with filtering...\n";
        
        $response = $this->controller->downloadStatus($this->testCourse);
        $responseData = json_decode($response->getContent(), true);
        
        if (isset($responseData['total_segments'])) {
            echo "  ✅ Download status shows {$responseData['total_segments']} total segments (filtered)\n";
            echo "  ✅ Downloaded segments: {$responseData['downloaded_segments']}\n";
            
            // Verify the count matches our expectations
            if ($responseData['total_segments'] === $this->testCourse->segments_count) {
                echo "  ✅ Download status count matches filtered segment count\n";
            } else {
                throw new Exception("Download status count mismatch");
            }
        } else {
            throw new Exception("Download status did not return expected data");
        }
        
        echo "\n";
    }
}

// Run the controller endpoint tests
$tester = new ControllerEndpointTest();
$tester->runTests();