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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->string('author')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('slug')->unique()->nullable();
            
            // Source information
            $table->enum('source_type', ['youtube', 'video', 'transcript', 'manual'])->default('manual');
            $table->string('source_url')->nullable();
            $table->string('source_file')->nullable();
            $table->longText('transcript')->nullable();
            
            // Link to video if generated from Thoth transcript
            $table->foreignId('video_id')->nullable()->constrained('videos')->nullOnDelete();
            
            // Status and workflow
            $table->enum('status', ['generating', 'draft', 'published', 'archived', 'error'])->default('draft');
            $table->text('error_message')->nullable();
            
            // Multi-brand support
            $table->string('brand_id', 50)->default('truefire');
            $table->index('brand_id');
            
            // Audit fields
            $table->string('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};


