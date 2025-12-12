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
        Schema::create('article_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->string('user_name');
            $table->text('content');
            
            // For inline comments (highlighting specific text)
            $table->text('selection_text')->nullable();
            $table->integer('position_start')->nullable();
            $table->integer('position_end')->nullable();
            
            // Threaded comments support
            $table->foreignId('parent_id')->nullable()->constrained('article_comments')->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('article_comments')->cascadeOnDelete();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['article_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_comments');
    }
};


