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
        Schema::table('truefire_segment_processing', function (Blueprint $table) {
            // Update timestamp columns to use microsecond precision (6 digits)
            $table->timestamp('audio_extraction_started_at', 6)->nullable()->change();
            $table->timestamp('audio_extraction_completed_at', 6)->nullable()->change();
            $table->timestamp('transcription_started_at', 6)->nullable()->change();
            $table->timestamp('transcription_completed_at', 6)->nullable()->change();
            $table->timestamp('terminology_started_at', 6)->nullable()->change();
            $table->timestamp('terminology_completed_at', 6)->nullable()->change();
            $table->timestamp('completed_at', 6)->nullable()->change();
            
            // Also update the approval timestamp for consistency
            $table->timestamp('audio_extraction_approved_at', 6)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('truefire_segment_processing', function (Blueprint $table) {
            // Revert back to second precision
            $table->timestamp('audio_extraction_started_at')->nullable()->change();
            $table->timestamp('audio_extraction_completed_at')->nullable()->change();
            $table->timestamp('transcription_started_at')->nullable()->change();
            $table->timestamp('transcription_completed_at')->nullable()->change();
            $table->timestamp('terminology_started_at')->nullable()->change();
            $table->timestamp('terminology_completed_at')->nullable()->change();
            $table->timestamp('completed_at')->nullable()->change();
            $table->timestamp('audio_extraction_approved_at')->nullable()->change();
        });
    }
}; 