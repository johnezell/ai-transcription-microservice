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
            $table->boolean('audio_extraction_approved')->default(false)->after('audio_size');
            $table->timestamp('audio_extraction_approved_at')->nullable()->after('audio_extraction_approved');
            $table->string('audio_extraction_approved_by')->nullable()->after('audio_extraction_approved_at');
            $table->text('audio_extraction_notes')->nullable()->after('audio_extraction_approved_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn([
                'audio_extraction_approved',
                'audio_extraction_approved_at',
                'audio_extraction_approved_by',
                'audio_extraction_notes'
            ]);
        });
    }
}; 