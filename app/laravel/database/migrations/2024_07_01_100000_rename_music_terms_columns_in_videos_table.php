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
        Schema::table('videos', function (Blueprint $table) {
            // Rename music_terms_path to terminology_path
            $table->renameColumn('music_terms_path', 'terminology_path');
            
            // Rename music_terms_count to terminology_count
            $table->renameColumn('music_terms_count', 'terminology_count');
            
            // Rename music_terms_metadata to terminology_metadata
            $table->renameColumn('music_terms_metadata', 'terminology_metadata');
            
            // Rename has_music_terms to has_terminology
            $table->renameColumn('has_music_terms', 'has_terminology');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Reverse the renames
            $table->renameColumn('terminology_path', 'music_terms_path');
            $table->renameColumn('terminology_count', 'music_terms_count');
            $table->renameColumn('terminology_metadata', 'music_terms_metadata');
            $table->renameColumn('has_terminology', 'has_music_terms');
        });
    }
}; 