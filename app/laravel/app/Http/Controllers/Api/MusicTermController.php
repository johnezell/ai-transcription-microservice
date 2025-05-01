<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class MusicTermController extends Controller
{
    /**
     * Get music term recognition data for a video
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
            
            if (!$video->has_music_terms) {
                return response()->json([
                    'success' => false,
                    'message' => 'No music terms available for this video'
                ], 404);
            }
            
            // If we have a music terms path, try to get the data from the file
            if ($video->music_terms_path) {
                // First determine if the path is absolute or relative
                $path = $video->music_terms_path;
                
                // In case of absolute path within the container
                if (str_starts_with($path, '/var/www/storage/app/public/')) {
                    $path = str_replace('/var/www/storage/app/public/', '', $path);
                }
                
                // Check if the file exists in storage
                if (Storage::disk('public')->exists($path)) {
                    $musicTermsData = json_decode(Storage::disk('public')->get($path), true);
                    
                    return response()->json([
                        'success' => true,
                        'music_terms' => $musicTermsData,
                        'metadata' => $video->music_terms_metadata,
                        'count' => $video->music_terms_count
                    ]);
                }
            }
            
            // If we get here, we have has_music_terms but couldn't load the data
            return response()->json([
                'success' => false,
                'message' => 'Music terms file not found',
                'metadata' => $video->music_terms_metadata,
                'count' => $video->music_terms_count
            ], 404);
            
        } catch (\Exception $e) {
            Log::error('Error retrieving music terms data: ' . $e->getMessage(), [
                'video_id' => $id,
                'exception' => $e
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving music terms data: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Manually trigger music term recognition for a video
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
                    'message' => 'Video must have a transcript before identifying music terms'
                ], 400);
            }
            
            // Update the video status to processing music terms
            $video->update([
                'status' => 'processing_music_terms'
            ]);
            
            // Get the music term service URL
            $musicTermServiceUrl = env('MUSIC_TERM_SERVICE_URL', 'http://music-term-recognition-service:5000');
            
            // Send request to the music term recognition service
            $response = Http::timeout(5)->post("{$musicTermServiceUrl}/process", [
                'job_id' => (string) $video->id
            ]);
            
            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Music term recognition started successfully',
                    'data' => $response->json()
                ]);
            } else {
                // Reset status if failed to start
                $video->update([
                    'status' => 'completed'
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to start music term recognition: ' . $response->body()
                ], 500);
            }
            
        } catch (\Exception $e) {
            Log::error('Error starting music term recognition: ' . $e->getMessage(), [
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
                'message' => 'Error starting music term recognition: ' . $e->getMessage()
            ], 500);
        }
    }
} 