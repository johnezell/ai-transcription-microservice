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
            // Add transcription testing fields
            $table->boolean('is_transcription_test')->default(false)->after('is_test_extraction');
            $table->string('test_transcription_preset')->nullable()->after('test_quality_level');
            $table->json('transcription_result')->nullable()->after('response_data');
            
            // Add indexes for efficient querying
            $table->index('is_transcription_test');
            $table->index(['is_transcription_test', 'test_transcription_preset']);
            $table->index(['is_transcription_test', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcription_logs', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['is_transcription_test', 'status']);
            $table->dropIndex(['is_transcription_test', 'test_transcription_preset']);
            $table->dropIndex(['is_transcription_test']);
            
            // Drop columns
            $table->dropColumn([
                'is_transcription_test',
                'test_transcription_preset',
                'transcription_result'
            ]);
        });
    }
};
