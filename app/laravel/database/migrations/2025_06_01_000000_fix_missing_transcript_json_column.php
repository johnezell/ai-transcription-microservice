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
        // Add the missing transcript_json column if it doesn't exist
        if (Schema::hasTable('videos') && !Schema::hasColumn('videos', 'transcript_json')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->longText('transcript_json')->nullable()->after('transcript_text');
            });
        }

        // Also add transcript_srt column if missing
        if (Schema::hasTable('videos') && !Schema::hasColumn('videos', 'transcript_srt')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->longText('transcript_srt')->nullable()->after('transcript_json');
            });
        }

        // Add terminology_json column if missing (after music_terms_metadata)
        if (Schema::hasTable('videos') && !Schema::hasColumn('videos', 'terminology_json')) {
            Schema::table('videos', function (Blueprint $table) {
                $table->longText('terminology_json')->nullable()->after('music_terms_metadata');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only drop columns if they exist to prevent errors
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