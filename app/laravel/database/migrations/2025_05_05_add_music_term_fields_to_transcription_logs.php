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
        Schema::table('transcription_logs', function (Blueprint $table) {
            $table->timestamp('music_term_recognition_started_at')->nullable()->after('transcription_completed_at')->comment('When music term recognition started');
            $table->timestamp('music_term_recognition_completed_at')->nullable()->after('music_term_recognition_started_at')->comment('When music term recognition completed');
            $table->float('music_term_recognition_duration_seconds')->nullable()->after('transcription_duration_seconds')->comment('Duration of music term recognition in seconds');
            $table->integer('music_term_count')->nullable()->after('music_term_recognition_duration_seconds')->comment('Number of music terms identified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcription_logs', function (Blueprint $table) {
            $table->dropColumn([
                'music_term_recognition_started_at',
                'music_term_recognition_completed_at',
                'music_term_recognition_duration_seconds',
                'music_term_count'
            ]);
        });
    }
}; 