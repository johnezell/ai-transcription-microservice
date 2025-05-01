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
            $table->string('music_terms_path')->nullable();
            $table->integer('music_terms_count')->nullable();
            $table->json('music_terms_metadata')->nullable();
            $table->boolean('has_music_terms')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('music_terms_path');
            $table->dropColumn('music_terms_count');
            $table->dropColumn('music_terms_metadata');
            $table->dropColumn('has_music_terms');
        });
    }
};
