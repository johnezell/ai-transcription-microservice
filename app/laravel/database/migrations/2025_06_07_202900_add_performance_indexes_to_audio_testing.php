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
            // Composite indexes for frequently queried combinations
            $table->index(['is_test_extraction', 'created_at'], 'idx_test_extraction_created');
            $table->index(['is_test_extraction', 'status'], 'idx_test_extraction_status');
            $table->index(['is_test_extraction', 'test_quality_level'], 'idx_test_extraction_quality');
            $table->index(['audio_test_batch_id', 'status'], 'idx_batch_status');
            $table->index(['audio_test_batch_id', 'batch_position'], 'idx_batch_position');
            
            // Performance-specific indexes
            $table->index(['created_at', 'total_processing_duration_seconds'], 'idx_created_processing_time');
            $table->index(['test_quality_level', 'total_processing_duration_seconds'], 'idx_quality_processing_time');
            $table->index(['status', 'completed_at'], 'idx_status_completed');
            
            // Partial indexes for status-based queries (MySQL doesn't support partial indexes, so we use regular indexes)
            $table->index(['status', 'is_test_extraction', 'created_at'], 'idx_status_test_created');
            
            // Foreign key optimization
            $table->index('video_id', 'idx_video_id');
            $table->index('audio_test_batch_id', 'idx_audio_test_batch_id');
        });

        Schema::table('audio_test_batches', function (Blueprint $table) {
            // Composite indexes for batch queries
            $table->index(['user_id', 'status'], 'idx_user_status');
            $table->index(['user_id', 'created_at'], 'idx_user_created');
            $table->index(['truefire_course_id', 'status'], 'idx_course_status');
            $table->index(['status', 'created_at'], 'idx_status_created');
            
            // Performance monitoring indexes
            $table->index(['quality_level', 'status'], 'idx_quality_status');
            $table->index(['concurrent_jobs', 'created_at'], 'idx_concurrent_created');
            $table->index(['total_segments', 'status'], 'idx_segments_status');
            
            // Time-based indexes for metrics
            $table->index(['started_at', 'completed_at'], 'idx_duration_calculation');
            $table->index(['created_at', 'quality_level'], 'idx_created_quality');
        });

        // Add indexes to job batches table if it exists (Laravel's job batching)
        if (Schema::hasTable('job_batches')) {
            Schema::table('job_batches', function (Blueprint $table) {
                if (!Schema::hasColumn('job_batches', 'name')) {
                    // Index might already exist, check first
                    $indexes = Schema::getConnection()->getDoctrineSchemaManager()
                        ->listTableIndexes('job_batches');
                    
                    if (!isset($indexes['idx_name_created'])) {
                        $table->index(['name', 'created_at'], 'idx_name_created');
                    }
                    if (!isset($indexes['idx_finished_created'])) {
                        $table->index(['finished_at', 'created_at'], 'idx_finished_created');
                    }
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcription_logs', function (Blueprint $table) {
            $table->dropIndex('idx_test_extraction_created');
            $table->dropIndex('idx_test_extraction_status');
            $table->dropIndex('idx_test_extraction_quality');
            $table->dropIndex('idx_batch_status');
            $table->dropIndex('idx_batch_position');
            $table->dropIndex('idx_created_processing_time');
            $table->dropIndex('idx_quality_processing_time');
            $table->dropIndex('idx_status_completed');
            $table->dropIndex('idx_status_test_created');
            $table->dropIndex('idx_video_id');
            $table->dropIndex('idx_audio_test_batch_id');
        });

        Schema::table('audio_test_batches', function (Blueprint $table) {
            $table->dropIndex('idx_user_status');
            $table->dropIndex('idx_user_created');
            $table->dropIndex('idx_course_status');
            $table->dropIndex('idx_status_created');
            $table->dropIndex('idx_quality_status');
            $table->dropIndex('idx_concurrent_created');
            $table->dropIndex('idx_segments_status');
            $table->dropIndex('idx_duration_calculation');
            $table->dropIndex('idx_created_quality');
        });

        if (Schema::hasTable('job_batches')) {
            Schema::table('job_batches', function (Blueprint $table) {
                $table->dropIndex('idx_name_created');
                $table->dropIndex('idx_finished_created');
            });
        }
    }
};