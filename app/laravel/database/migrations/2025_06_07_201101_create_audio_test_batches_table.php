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
        Schema::create('audio_test_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('truefire_course_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('quality_level', ['fast', 'balanced', 'high', 'premium'])->default('balanced');
            $table->json('extraction_settings')->nullable();
            $table->json('segment_ids');
            $table->integer('total_segments')->default(0);
            $table->integer('completed_segments')->default(0);
            $table->integer('failed_segments')->default(0);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('estimated_duration')->nullable()->comment('Estimated processing time in seconds');
            $table->integer('actual_duration')->nullable()->comment('Actual processing time in seconds');
            $table->integer('concurrent_jobs')->default(3)->comment('Number of concurrent jobs to run');
            $table->string('batch_job_id')->nullable()->comment('Laravel batch job ID');
            $table->timestamps();
            
            // Indexes for efficient queries
            $table->index(['user_id', 'status']);
            $table->index(['truefire_course_id', 'status']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audio_test_batches');
    }
};
