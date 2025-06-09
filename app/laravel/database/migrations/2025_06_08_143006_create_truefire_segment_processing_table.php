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
        Schema::create('truefire_segment_processing', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('segment_id');
            $table->unsignedBigInteger('course_id');
            $table->string('status')->default('ready'); // ready, processing, audio_extracted, transcribing, transcribed, processing_terminology, completed, failed
            $table->integer('progress_percentage')->default(0);
            $table->text('error_message')->nullable();
            
            // Audio extraction
            $table->string('audio_path')->nullable();
            $table->bigInteger('audio_size')->nullable();
            $table->float('audio_duration')->nullable();
            $table->boolean('audio_extraction_approved')->default(false);
            $table->timestamp('audio_extraction_approved_at')->nullable();
            $table->string('audio_extraction_approved_by')->nullable();
            $table->text('audio_extraction_notes')->nullable();
            
            // Transcription
            $table->string('transcript_path')->nullable();
            $table->longText('transcript_text')->nullable();
            $table->json('transcript_json')->nullable();
            
            // Terminology
            $table->boolean('has_terminology')->default(false);
            $table->string('terminology_path')->nullable();
            $table->json('terminology_json')->nullable();
            $table->integer('terminology_count')->nullable();
            $table->json('terminology_metadata')->nullable();
            
            // Processing timestamps
            $table->timestamp('audio_extraction_started_at')->nullable();
            $table->timestamp('audio_extraction_completed_at')->nullable();
            $table->timestamp('transcription_started_at')->nullable();
            $table->timestamp('transcription_completed_at')->nullable();
            $table->timestamp('terminology_started_at')->nullable();
            $table->timestamp('terminology_completed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('segment_id');
            $table->index('course_id');
            $table->index('status');
            $table->index(['segment_id', 'course_id']);
            
            // Foreign key constraints (optional if you want to enforce referential integrity)
            // Note: These would require the local_truefire_segments and local_truefire_courses tables to exist
            // $table->foreign('segment_id')->references('id')->on('local_truefire_segments')->onDelete('cascade');
            // $table->foreign('course_id')->references('id')->on('local_truefire_courses')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('truefire_segment_processing');
    }
};
