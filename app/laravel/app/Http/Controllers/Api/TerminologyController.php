<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
// use App\Models\TerminologyCategory as Category; // Keep FQCN for clarity in export
// use App\Models\Terminology as Term;           // Keep FQCN for clarity in export

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
            $video = Video::findOrFail($id);
            
            if (!$video->has_terminology) {
                return response()->json([
                    'success' => false,
                    'message' => 'No terminology available for this video'
                ], 404);
            }
            
            if (!empty($video->terminology_json)) {
                return response()->json([
                    'success' => true,
                    'terminology' => $video->terminology_json,
                    'metadata' => $video->terminology_metadata,
                    'count' => $video->terminology_count
                ]);
            }
            
            if ($video->terminology_path) {
                $path = $video->terminology_path;
                if (Storage::disk('s3')->exists($path)) { 
                    $jsonData = Storage::disk('s3')->get($path);
                    $terminologyData = json_decode($jsonData, true);
                    return response()->json([
                        'success' => true,
                        'terminology' => $terminologyData,
                        'metadata' => $video->terminology_metadata,
                        'count' => $video->terminology_count
                    ]);
                }
            }
            
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
            
            $video->update(['status' => 'processing_terminology']); 
            
            $serviceUrl = env('TERMINOLOGY_SERVICE_URL', 'http://terminology-service.local:5000');
            
            Log::info('Dispatching terminology recognition request via API controller', [
                'video_id' => $id,
                'service_url' => $serviceUrl,
                'transcript_s3_key' => $video->transcript_path
            ]);
            
            $response = Http::timeout(300)
                            ->post("{$serviceUrl}/process", [
                                'job_id' => (string) $video->id,
                                'transcript_s3_key' => $video->transcript_path
                            ]);
            
            if ($response->successful()) {
                $responseData = $response->json();
                Log::info('Terminology recognition successfully initiated via API.', ['video_id' => $id, 'service_response' => $responseData]);
                return response()->json([
                    'success' => true,
                    'message' => 'Terminology recognition process initiated successfully.',
                    'data' => $responseData
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
        $categories = \App\Models\TerminologyCategory::where('active', true) 
            ->with(['activeTerms' => function($query) { 
                $query->where('active', true)->orderBy('term');
            }])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $result = [];
        foreach ($categories as $category) {
            $termsData = [];
            foreach ($category->activeTerms as $termModel) {
                $termsData[] = [
                    'term' => $termModel->term,
                    'patterns' => $termModel->patterns ?? [strtolower($termModel->term)],
                    'description' => $termModel->description,
                ];
            }
            if (!empty($termsData)) {
                $result[$category->slug] = $termsData;
            }
        }
        return response()->json($result);
    }
} 