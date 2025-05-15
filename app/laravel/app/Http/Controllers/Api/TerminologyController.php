<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\TerminologyCategory as Category;
use App\Models\Terminology as Term;

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
                // This logic might be problematic if terminology_path is an S3 key
                // For S3, we should use Storage::disk('s3')->exists() and get()
                if (str_starts_with($path, '/var/www/storage/app/public/')) {
                    $path = str_replace('/var/www/storage/app/public/', '', $path);
                     if (Storage::disk('public')->exists($path)) {
                        $terminologyData = json_decode(Storage::disk('public')->get($path), true);
                        return response()->json([
                            'success' => true,
                            'terminology' => $terminologyData,
                            'metadata' => $video->terminology_metadata,
                            'count' => $video->terminology_count
                        ]);
                    }
                } elseif (Storage::disk('s3')->exists($path)) { // Check S3 directly if not a local public path
                    $terminologyData = json_decode(Storage::disk('s3')->get($path), true);
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
                'message' => 'Terminology file not found or inaccessible',
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
            $video = Video::findOrFail($id);
            
            if (empty($video->transcript_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Video must have a transcript before identifying terminology'
                ], 400);
            }
            
            // Ensure this status matches one defined in TranscriptionController validation
            $video->update(['status' => 'processing_terminology']); 
            
            // Use the correct environment variable for the Terminology service
            $serviceUrl = env('TERMINOLOGY_SERVICE_URL', 'http://terminology-service.local:5000');
            
            Log::info('Dispatching terminology recognition request via API controller', [
                'video_id' => $id,
                'service_url' => $serviceUrl,
                'transcript_s3_key' => $video->transcript_path // Pass the S3 key
            ]);
            
            $response = Http::timeout(300) // Increased timeout
                            ->post("{$serviceUrl}/process", [
                                'job_id' => (string) $video->id,
                                'transcript_s3_key' => $video->transcript_path // Python service expects this
                            ]);
            
            if ($response->successful()) {
                $responseData = $response->json();
                Log::info('Terminology recognition successfully initiated via API.', ['video_id' => $id, 'service_response' => $responseData]);
                // The actual update of video model with terminology data will happen 
                // when the Terminology service calls back to TranscriptionController@updateJobStatus
                return response()->json([
                    'success' => true,
                    'message' => 'Terminology recognition process initiated successfully.',
                    'data' => $responseData // Return what the service immediately responded with
                ]);
            } else {
                $video->update(['status' => 'failed', 'error_message' => 'Failed to dispatch to terminology service.']);
                Log::error('Failed to dispatch to terminology service via API controller.', ['video_id' => $id, 'status_code' => $response->status(), 'response_body' => $response->body()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to initiate terminology recognition: ' . $response->body()
                ], $response->status());
            }
            
        } catch (\Exception $e) {
            Log::error('Error initiating terminology recognition via API: ' . $e->getMessage(), [
                'video_id' => $id,
                'exception' => $e
            ]);
            try { $video->update(['status' => 'failed', 'error_message' => 'Exception during terminology dispatch.']); } catch (\Exception $_e) {}
            return response()->json([
                'success' => false,
                'message' => 'Error initiating terminology recognition: ' . $e->getMessage()
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
        Log::info('Terminology recognition triggered via API route', [
            'video_id' => $id
        ]);
        return $this->process($request, $id);
    }

    /**
     * Export all active terminology categories and their active terms.
     */
    public function export()
    {
        // Use the correct model: TerminologyCategory (aliased as Category)
        $categories = Category::where('active', true) 
            ->with(['activeTerms' => function($query) { // Assumes 'activeTerms' relation exists and filters active terms
                $query->orderBy('term');
            }])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $result = [];
        foreach ($categories as $category) {
            // The 'activeTerms' relationship should now correctly give Terminology model instances
            $result[$category->slug] = $category->activeTerms->pluck('term')->toArray(); 
        }
        return response()->json($result);
    }
} 