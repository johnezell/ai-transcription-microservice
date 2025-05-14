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
        Schema::create('transcription_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('video_id')->nullable(); // Should match videos.id type
            $table->string('job_id')->unique()->comment('Corresponds to video_id, ensures one log entry per video processing lifecycle');
            
            $table->string('status')->default('pending')->comment('Overall status of the multi-step process');
            $table->integer('progress_percentage')->default(0);

            $table->text('request_data')->nullable()->comment('Initial request data if any');
            $table->text('response_data')->nullable()->comment('Final response data from last successful step or error');
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable()->comment('When the first job in the chain was initiated');
            
            // Audio Extraction Timings & Data
            $table->timestamp('audio_extraction_started_at')->nullable();
            $table->timestamp('audio_extraction_completed_at')->nullable();
            $table->float('audio_extraction_duration_seconds')->nullable();
            $table->unsignedBigInteger('audio_file_size')->nullable(); // Renamed from just 'audio_size' for clarity
            $table->double('audio_duration_seconds', 10, 3)->nullable(); // Clarified, added precision from video table

            // Transcription Timings & Data
            $table->timestamp('transcription_started_at')->nullable();
            $table->timestamp('transcription_completed_at')->nullable();
            $table->float('transcription_duration_seconds')->nullable();

            // Terminology Analysis Timings & Data
            $table->timestamp('terminology_analysis_started_at')->nullable();
            $table->timestamp('terminology_analysis_completed_at')->nullable();
            $table->integer('terminology_term_count')->unsigned()->nullable()->default(0);
            $table->float('terminology_duration_seconds')->nullable();

            $table->timestamp('completed_at')->nullable()->comment('When the entire multi-step process completed or terminally failed');
            $table->float('total_processing_duration_seconds')->nullable();
            
            $table->timestamps(); // created_at, updated_at for the log record itself
            
            $table->foreign('video_id')->references('id')->on('videos')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcription_logs');
    }
};
