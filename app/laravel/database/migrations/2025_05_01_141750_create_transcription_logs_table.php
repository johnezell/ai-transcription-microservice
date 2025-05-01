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
        Schema::create('transcription_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('video_id')->nullable();
            $table->string('job_id')->unique()->comment('Unique job identifier');
            $table->string('status')->default('pending')->comment('Job status: pending, processing, completed, failed');
            $table->text('request_data')->nullable()->comment('JSON data sent to the transcription service');
            $table->text('response_data')->nullable()->comment('JSON response from the transcription service');
            $table->text('error_message')->nullable()->comment('Error message if job failed');
            $table->timestamp('started_at')->nullable()->comment('When the job started processing');
            $table->timestamp('completed_at')->nullable()->comment('When the job was completed or failed');
            $table->timestamps();
            
            $table->foreign('video_id')->references('id')->on('videos')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transcription_logs');
    }
};
