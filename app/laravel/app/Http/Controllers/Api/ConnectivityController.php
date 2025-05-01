<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ConnectivityController extends Controller
{
    /**
     * Test connectivity to all services
     */
    public function testConnectivity()
    {
        $results = [
            'laravel' => [
                'status' => 'ok',
                'message' => 'Laravel application is running'
            ]
        ];
        
        // Test Audio Extraction Service
        try {
            $audioServiceUrl = env('AUDIO_SERVICE_URL', 'http://audio-extraction-service:5000');
            $response = Http::get("{$audioServiceUrl}/connectivity-test");
            
            if ($response->successful()) {
                $results['audio_extraction_service'] = [
                    'status' => 'ok',
                    'data' => $response->json()
                ];
            } else {
                $results['audio_extraction_service'] = [
                    'status' => 'error',
                    'message' => 'Failed to connect to Audio Extraction Service',
                    'response' => $response->body()
                ];
            }
        } catch (\Exception $e) {
            $results['audio_extraction_service'] = [
                'status' => 'error',
                'message' => 'Exception when connecting to Audio Extraction Service',
                'error' => $e->getMessage()
            ];
            
            Log::error('Failed to connect to Audio Extraction Service', [
                'error' => $e->getMessage()
            ]);
        }
        
        // Test Transcription Service
        try {
            $transcriptionServiceUrl = env('TRANSCRIPTION_SERVICE_URL', 'http://transcription-service:5000');
            $response = Http::get("{$transcriptionServiceUrl}/connectivity-test");
            
            if ($response->successful()) {
                $results['transcription_service'] = [
                    'status' => 'ok',
                    'data' => $response->json()
                ];
            } else {
                $results['transcription_service'] = [
                    'status' => 'error',
                    'message' => 'Failed to connect to Transcription Service',
                    'response' => $response->body()
                ];
            }
        } catch (\Exception $e) {
            $results['transcription_service'] = [
                'status' => 'error',
                'message' => 'Exception when connecting to Transcription Service',
                'error' => $e->getMessage()
            ];
            
            Log::error('Failed to connect to Transcription Service', [
                'error' => $e->getMessage()
            ]);
        }
        
        // Add the route to the results
        $results['routes'] = [
            'hello' => route('api.hello'),
            'connectivity_test' => route('api.connectivity-test')
        ];
        
        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
            'results' => $results
        ]);
    }
} 