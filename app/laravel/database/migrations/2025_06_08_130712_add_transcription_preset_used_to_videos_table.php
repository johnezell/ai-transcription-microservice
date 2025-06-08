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
            $table->enum('transcription_preset_used', ['fast', 'balanced', 'high', 'premium'])
                  ->nullable()
                  ->after('transcript_srt')
                  ->comment('Transcription preset used for this video');
            
            // Index for efficient filtering and reporting
            $table->index('transcription_preset_used');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex(['transcription_preset_used']);
            $table->dropColumn('transcription_preset_used');
        });
    }
};
