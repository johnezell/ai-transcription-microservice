<?php

use App\Http\Controllers\Api\ConnectivityController;
use App\Http\Controllers\Api\HelloController;
use App\Http\Controllers\Api\TranscriptionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Hello World endpoint
Route::get('/hello', [HelloController::class, 'hello'])->name('api.hello');

// Connectivity test
Route::get('/connectivity-test', [ConnectivityController::class, 'testConnectivity'])->name('api.connectivity-test');

// Transcription endpoints
Route::post('/transcription', [TranscriptionController::class, 'dispatchJob']);
Route::get('/transcription/{jobId}', [TranscriptionController::class, 'getJobStatus']);
Route::post('/transcription/{jobId}/status', [TranscriptionController::class, 'updateJobStatus']);
Route::get('/test-python-service', [TranscriptionController::class, 'testPythonService']);
