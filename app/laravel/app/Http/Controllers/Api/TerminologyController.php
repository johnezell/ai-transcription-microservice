<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\TermCategory as Category;
use App\Models\Term;

class TerminologyController extends Controller
{
    /**
     * Get terminology data for a video
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
    {
        try {
            // Find the video
            $video = Video::findOrFail($id);
            
            if (!$video->has_terminology) {
                return response()->json([
                    'success' => false,
                    'message' => 'No terminology available for this video'
                ], 404);
            }
            
            // Try to get the data from the database first
            if (!empty($video->terminology_json)) {
                return response()->json([
                    'success' => true,
                    'terminology' => $video->terminology_json,
                    'metadata' => $video->terminology_metadata,
                    'count' => $video->terminology_count
                ]);
            }
            
            // If we have a terminology path, try to get the data from the file
            if ($video->terminology_path) {
                // First determine if the path is absolute or relative
                $path = $video->terminology_path;
                
                // In case of absolute path within the container
                if (str_starts_with($path, '/var/www/storage/app/public/')) {
                    $path = str_replace('/var/www/storage/app/public/', '', $path);
                }
                
                // Check if the file exists in storage
                if (Storage::disk('public')->exists($path)) {
                    $terminologyData = json_decode(Storage::disk('public')->get($path), true);
                    
                    return response()->json([
                        'success' => true,
                        'terminology' => $terminologyData,
                        'metadata' => $video->terminology_metadata,
                        'count' => $video->terminology_count
                    ]);
                }
            }
            
            // If we get here, we have has_terminology but couldn't load the data
            return response()->json([
                'success' => false,
                'message' => 'Terminology file not found',
                'metadata' => $video->terminology_metadata,
                'count' => $video->terminology_count
            ], 404);
            
        } catch (\Exception $e) {
            Log::error('Error retrieving terminology data: ' . $e->getMessage(), [
                'video_id' => $id,
                'exception' => $e
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving terminology data: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Manually trigger terminology recognition for a video
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function process(Request $request, $id)
    {
        try {
            // Find the video
            $video = Video::findOrFail($id);
            
            // Check if the video has a transcript
            if (empty($video->transcript_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video must have a transcript before identifying terminology'
                ], 400);
            }
            
            // Update the video status to processing terminology
            $video->update([
                'status' => 'processing_music_terms' // Keep this name for backward compatibility
            ]);
            
            // Get the terminology service URL
            $serviceUrl = env('MUSIC_TERM_SERVICE_URL', 'http://music-term-recognition-service:5000');
            
            Log::info('Starting terminology recognition for video', [
                'video_id' => $id,
                'service_url' => $serviceUrl
            ]);
            
            // Send request to the terminology recognition service
            $response = Http::timeout(10)->post("{$serviceUrl}/process", [
                'job_id' => (string) $video->id
            ]);
            
            if ($response->successful()) {
                // Get the response data
                $responseData = $response->json();
                
                // If the response already contains the music_terms_json_path, it means the service
                // has processed the request synchronously. In this case, immediately mark as completed.
                if (isset($responseData['data']) && isset($responseData['data']['music_terms_json_path'])) {
                    Log::info('Received synchronous terminology result - forcing status update to completed', [
                        'video_id' => $id,
                        'music_terms_json_path' => $responseData['data']['music_terms_json_path']
                    ]);
                    
                    // Update the video with terminology data and completed status
                    $video->update([
                        'terminology_path' => $responseData['data']['music_terms_json_path'],
                        'terminology_count' => $responseData['data']['term_count'] ?? 0,
                        'has_terminology' => true,
                        'status' => 'completed' // Force status to completed
                    ]);
                    
                    // If categories are available, save them as metadata
                    if (isset($responseData['data']['categories'])) {
                        $video->update([
                            'terminology_metadata' => [
                                'categories' => $responseData['data']['categories'],
                                'service_timestamp' => $responseData['data']['service_timestamp'] ?? now()->toIso8601String(),
                            ]
                        ]);
                    }
                }
                
                return response()->json([
                    'success' => true,
                    'message' => 'Terminology recognition started successfully',
                    'data' => $responseData
                ]);
            } else {
                // Reset status if failed to start
                $video->update([
                    'status' => 'completed'
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to start terminology recognition: ' . $response->body()
                ], 500);
            }
            
        } catch (\Exception $e) {
            Log::error('Error starting terminology recognition: ' . $e->getMessage(), [
                'video_id' => $id,
                'exception' => $e
            ]);
            
            // Reset status if exception occurred
            try {
                $video->update([
                    'status' => 'completed'
                ]);
            } catch (\Exception $innerEx) {
                // Log but continue
                Log::error('Error updating video status: ' . $innerEx->getMessage());
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Error starting terminology recognition: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger terminology recognition for a video.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function triggerRecognition(Request $request, $id)
    {
        Log::info('Terminology recognition triggered', [
            'video_id' => $id
        ]);
        
        return $this->process($request, $id);
    }

    public function export(){
        $categories = Category::with(['terms'])->get();

        $result = [];

        foreach ($categories as $category) {
            $terms = $category->terms->pluck('term')->toArray();    
            $result[$category->slug] = $terms;
        }

        return response()->json($result);
    }

    
} 