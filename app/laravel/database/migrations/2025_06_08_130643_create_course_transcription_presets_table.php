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
        Schema::create('course_transcription_presets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('truefire_course_id')->index();
            $table->enum('transcription_preset', ['fast', 'balanced', 'high', 'premium'])
                  ->default('balanced')
                  ->comment('Transcription quality preset for Whisper model selection');
            $table->json('settings')->nullable()->comment('Additional transcription settings');
            $table->timestamps();
            
            // Ensure one preset per course
            $table->unique('truefire_course_id');
            
            // Index for efficient lookups
            $table->index(['truefire_course_id', 'transcription_preset']);
            
            // Foreign key constraint to local_truefire_courses table
            $table->foreign('truefire_course_id')->references('id')->on('local_truefire_courses')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_transcription_presets');
    }
};
