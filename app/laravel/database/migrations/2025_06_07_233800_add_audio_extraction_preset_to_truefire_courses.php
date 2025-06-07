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
        Schema::connection('truefire')->table('courses', function (Blueprint $table) {
            $table->enum('audio_extraction_preset', ['fast', 'balanced', 'high', 'premium'])
                  ->default('balanced')
                  ->after('id')
                  ->comment('Audio extraction quality preset for batch processing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('truefire')->table('courses', function (Blueprint $table) {
            $table->dropColumn('audio_extraction_preset');
        });
    }
};