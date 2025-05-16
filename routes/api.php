<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ApiController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_middleware'),
    'verified'
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/cross-db/example', function() {
    try {
        $results = DB::select("
            SELECT c.id, s.video, s.id as segment_id 
            FROM truefire.courses c 
            JOIN channels.channels ch ON ch.courseid=c.id 
            JOIN channels.segments s ON s.channel_id=ch.id
        ");
        
        return response()->json([
            'success' => true,
            'data' => $results
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error executing query: ' . $e->getMessage()
        ], 500);
    }
}); 