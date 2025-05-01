<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class VideoController extends Controller
{
    /**
     * Display a listing of the videos.
     */
    public function index()
    {
        $videos = Video::latest()->get();
        
        return Inertia::render('Videos/Index', [
            'videos' => $videos,
        ]);
    }
    
    /**
     * Show the form for creating a new video.
     */
    public function create()
    {
        return Inertia::render('Videos/Create');
    }
    
    /**
     * Store a newly uploaded video.
     */
    public function store(Request $request)
    {
        $request->validate([
            'video' => 'required|file|mimetypes:video/mp4,video/mpeg,video/quicktime', // No size limit
        ]);
        
        $file = $request->file('video');
        $originalFilename = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        
        // Generate a unique S3-like key (simulating S3 storage)
        $s3Key = 'source_videos/' . Str::uuid() . '.' . $extension;
        $storagePath = 'public/s3/' . $s3Key;
        
        // Store the file in our "s3" directory
        $file->storeAs('public/s3/source_videos', basename($s3Key));
        
        // Create database record
        $video = Video::create([
            'original_filename' => $originalFilename,
            'storage_path' => $storagePath,
            's3_key' => $s3Key,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'status' => 'uploaded',
            'metadata' => [
                'uploaded_at' => now()->toIso8601String(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        ]);
        
        return redirect()->route('videos.index')
            ->with('success', 'Video uploaded successfully.');
    }
    
    /**
     * Display the specified video.
     */
    public function show(Video $video)
    {
        return Inertia::render('Videos/Show', [
            'video' => $video,
        ]);
    }
    
    /**
     * Remove the specified video from storage.
     */
    public function destroy(Video $video)
    {
        // Delete the file
        Storage::delete($video->storage_path);
        
        // Delete the record
        $video->delete();
        
        return redirect()->route('videos.index')
            ->with('success', 'Video deleted successfully.');
    }
    
    /**
     * Request transcription for the video.
     */
    public function requestTranscription(Video $video)
    {
        // Mark video as processing
        $video->update([
            'status' => 'processing'
        ]);
        
        // TODO: Connect with transcription service
        
        return redirect()->route('videos.show', $video)
            ->with('success', 'Transcription requested.');
    }
}
