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
        Schema::create('course_audio_presets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('truefire_course_id')->index();
            $table->enum('audio_extraction_preset', ['fast', 'balanced', 'high', 'premium'])
                  ->default('balanced')
                  ->comment('Audio extraction quality preset for batch processing');
            $table->json('settings')->nullable()->comment('Additional extraction settings');
            $table->timestamps();
            
            // Ensure one preset per course
            $table->unique('truefire_course_id');
            
            // Index for efficient lookups
            $table->index(['truefire_course_id', 'audio_extraction_preset']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_audio_presets');
    }
};