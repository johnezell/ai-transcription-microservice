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
        // Only proceed if the videos table exists
        if (Schema::hasTable('videos')) {
            Schema::table('videos', function (Blueprint $table) {
                // Add column for storing the transcript JSON data if it doesn't exist
                if (!Schema::hasColumn('videos', 'transcript_json')) {
                    $table->longText('transcript_json')->nullable()->after('transcript_text');
                }
                
                // Add column for storing the SRT content if it doesn't exist
                if (!Schema::hasColumn('videos', 'transcript_srt')) {
                    $table->longText('transcript_srt')->nullable()->after('transcript_json');
                }
                
                // Add column for storing the terminology JSON data if it doesn't exist
                if (!Schema::hasColumn('videos', 'terminology_json')) {
                    $table->longText('terminology_json')->nullable()->after('music_terms_metadata');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only proceed if the videos table exists
        if (Schema::hasTable('videos')) {
            Schema::table('videos', function (Blueprint $table) {
                if (Schema::hasColumn('videos', 'transcript_json')) {
                    $table->dropColumn('transcript_json');
                }
                
                if (Schema::hasColumn('videos', 'transcript_srt')) {
                    $table->dropColumn('transcript_srt');
                }
                
                if (Schema::hasColumn('videos', 'terminology_json')) {
                    $table->dropColumn('terminology_json');
                }
            });
        }
    }
}; 