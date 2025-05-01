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
        Schema::table('videos', function (Blueprint $table) {
            // Add column for storing the transcript JSON data
            $table->longText('transcript_json')->nullable()->after('transcript_text');
            
            // Add column for storing the SRT content
            $table->longText('transcript_srt')->nullable()->after('transcript_json');
            
            // Add column for storing the terminology JSON data (renamed from music_terms_json)
            $table->longText('terminology_json')->nullable()->after('music_terms_metadata');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('transcript_json');
            $table->dropColumn('transcript_srt');
            $table->dropColumn('terminology_json');
        });
    }
}; 