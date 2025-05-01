<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTranscriptionJob;
use App\Models\TranscriptionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TranscriptionController extends Controller
{
    /**
     * Dispatch a new transcription job to the queue.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function dispatchJob(Request $request)
    {
        // Validate request
        $request->validate([
            'filename' => 'required|string',
            'type' => 'required|in:audio,video',
        ]);

        // Generate a unique job ID
        $jobId = (string) Str::uuid();

        // Create a transcription log entry
        $log = TranscriptionLog::create([
            'job_id' => $jobId,
            'status' => 'pending',
            'request_data' => $request->all(),
        ]);

        // Dispatch the job to the queue
        ProcessTranscriptionJob::dispatch($log);

        // Return response with job ID
        return response()->json([
            'success' => true,
            'message' => 'Transcription job dispatched successfully',
            'job_id' => $jobId,
        ], 202);
    }

    /**
     * Get the status of a transcription job.
     *
     * @param  string  $jobId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getJobStatus($jobId)
    {
        $log = TranscriptionLog::where('job_id', $jobId)->first();

        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'job_id' => $log->job_id,
                'status' => $log->status,
                'created_at' => $log->created_at,
                'started_at' => $log->started_at,
                'completed_at' => $log->completed_at,
                'error_message' => $log->error_message,
                'response_data' => $log->response_data,
            ],
        ]);
    }

    /**
     * Update the status of a transcription job.
     * This endpoint is called by the Python service.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $jobId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateJobStatus(Request $request, $jobId)
    {
        $log = TranscriptionLog::where('job_id', $jobId)->first();

        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'Job not found',
            ], 404);
        }

        // Validate request
        $request->validate([
            'status' => 'required|string|in:processing,completed,failed',
            'completed_at' => 'nullable|date',
            'response_data' => 'nullable',
            'error_message' => 'nullable|string',
        ]);

        // Update the log
        $log->update([
            'status' => $request->status,
            'completed_at' => $request->completed_at ?? $log->completed_at,
            'response_data' => $request->response_data ?? $log->response_data,
            'error_message' => $request->error_message ?? $log->error_message,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Job status updated successfully',
            'job_id' => $jobId,
        ]);
    }

    /**
     * Test the connection to the Python service.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testPythonService()
    {
        try {
            $pythonServiceUrl = env('PYTHON_SERVICE_URL', 'http://transcription-service:5000');
            $healthUrl = "{$pythonServiceUrl}/health";
            
            $response = Http::get($healthUrl);
            
            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Successfully connected to Python service',
                    'python_service_response' => $response->json(),
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to connect to Python service',
                    'status_code' => $response->status(),
                    'response' => $response->body(),
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to connect to Python service',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
