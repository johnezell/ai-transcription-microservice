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
        Schema::create('terminologies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('terminology_categories')->onDelete('cascade');
            $table->string('term');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->json('patterns')->nullable(); // For storing regex or other matching patterns
            $table->unique(['category_id', 'term']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terminologies');
    }
}; 