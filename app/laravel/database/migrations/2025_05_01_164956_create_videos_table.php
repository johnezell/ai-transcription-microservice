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
            
            // $table->foreignUuid('course_id')->nullable()->constrained()->nullOnDelete(); // Old way
            $table->foreignUuid('course_id')->nullable(); // Define column first
            $table->foreign('course_id')        // Then define foreign key constraint explicitly
                  ->references('id')
                  ->on('courses')
                  ->nullOnDelete();

            $table->integer('lesson_number')->nullable();
            
            // Basic video information
            $table->string('original_filename');
            $table->string('storage_path')->nullable(); // S3 key for original video
            $table->string('s3_key')->nullable();       // Original video filename part (might be redundant if storage_path is full key)
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            
            // Status tracking
            $table->string('status')->default('uploading');
            
            // Additional metadata (e.g., upload details)
            $table->json('metadata')->nullable();
            
            // Audio extraction information
            $table->string('audio_path')->nullable();       // S3 key for extracted audio.wav
            $table->double('audio_duration', 10, 3)->nullable(); // Duration in seconds, with precision
            $table->unsignedBigInteger('audio_size')->nullable();   // Size in bytes

            // Transcription information
            $table->string('transcript_path')->nullable();   // S3 key for .txt transcript
            $table->mediumText('transcript_text')->nullable(); // Full transcript text
            $table->json('transcript_json')->nullable();      // Parsed JSON from Whisper (or from transcript.json on S3)
            $table->mediumText('transcript_srt')->nullable();   // SRT content

            // Terminology fields
            $table->string('terminology_path')->nullable();      // S3 key for terminology.json
            $table->json('terminology_json')->nullable();        // Parsed JSON content from terminology service
            $table->boolean('has_terminology')->default(false);
            $table->integer('terminology_count')->unsigned()->nullable()->default(0);
            $table->json('terminology_metadata')->nullable();   // For category summaries, etc.

            // Error message field
            $table->text('error_message')->nullable();
            
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