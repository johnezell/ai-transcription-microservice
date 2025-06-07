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
            $table->boolean('is_test_extraction')->default(false)->after('music_term_count');
            $table->enum('test_quality_level', ['fast', 'balanced', 'high', 'premium'])->nullable()->after('is_test_extraction');
            $table->json('audio_quality_metrics')->nullable()->after('test_quality_level');
            $table->json('extraction_settings')->nullable()->after('audio_quality_metrics');
            
            // Add index for efficient test queries
            $table->index('is_test_extraction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcription_logs', function (Blueprint $table) {
            $table->dropIndex(['is_test_extraction']);
            $table->dropColumn([
                'is_test_extraction',
                'test_quality_level',
                'audio_quality_metrics',
                'extraction_settings'
            ]);
        });
    }
};
