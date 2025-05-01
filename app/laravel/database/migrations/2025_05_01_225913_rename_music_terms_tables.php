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
        // Rename music_term_categories to term_categories
        Schema::rename('music_term_categories', 'term_categories');
        
        // Rename music_terms to terms
        Schema::rename('music_terms', 'terms');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert renames
        Schema::rename('term_categories', 'music_term_categories');
        Schema::rename('terms', 'music_terms');
    }
};
