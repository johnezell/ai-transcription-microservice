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
        Schema::create('segment_downloads', function (Blueprint $table) {
            $table->id();
            $table->string('segment_id')->unique()->comment('Unique segment ID to prevent duplicates');
            $table->string('course_id')->nullable()->comment('Course ID for organization');
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])
                  ->default('queued')
                  ->comment('Current status of the download');
            $table->timestamp('queued_at')->nullable()->comment('When the job was queued');
            $table->timestamp('started_at')->nullable()->comment('When processing started');
            $table->timestamp('completed_at')->nullable()->comment('When processing completed');
            $table->text('error_message')->nullable()->comment('Error message if failed');
            $table->integer('attempts')->default(0)->comment('Number of attempts made');
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['status', 'created_at']);
            $table->index(['course_id', 'status']);
            $table->index(['started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('segment_downloads');
    }
};