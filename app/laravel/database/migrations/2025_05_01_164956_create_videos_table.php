<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Basic video information
            $table->string('original_filename');
            $table->string('storage_path')->nullable(); // Allow null initially
            $table->string('s3_key')->nullable(); 
            $table->string('mime_type');
            $table->bigInteger('size_bytes');
            
            // Status tracking
            $table->string('status')->default('uploading')->comment('uploading, uploaded, processing, extracting_audio, transcribing, completed, failed');
            
            // Additional metadata
            $table->json('metadata')->nullable();
            
            // Audio extraction information
            $table->string('audio_path')->nullable();
            $table->float('audio_duration')->nullable();
            $table->bigInteger('audio_size')->nullable();
            
            // Transcription information
            $table->string('transcript_path')->nullable();
            $table->text('transcript_text')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
}; 