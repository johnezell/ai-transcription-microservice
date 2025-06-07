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
            $table->foreignId('audio_test_batch_id')->nullable()->after('extraction_settings')->constrained('audio_test_batches')->onDelete('set null');
            $table->integer('batch_position')->nullable()->after('audio_test_batch_id')->comment('Position within batch processing order');
            
            // Add indexes for efficient batch queries
            $table->index('audio_test_batch_id');
            $table->index(['audio_test_batch_id', 'batch_position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcription_logs', function (Blueprint $table) {
            $table->dropForeign(['audio_test_batch_id']);
            $table->dropIndex(['audio_test_batch_id']);
            $table->dropIndex(['audio_test_batch_id', 'batch_position']);
            $table->dropColumn(['audio_test_batch_id', 'batch_position']);
        });
    }
};
