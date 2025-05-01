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
        Schema::table('transcription_logs', function (Blueprint $table) {
            $table->timestamp('audio_extraction_started_at')->nullable()->after('started_at')->comment('When audio extraction started');
            $table->timestamp('audio_extraction_completed_at')->nullable()->after('audio_extraction_started_at')->comment('When audio extraction completed');
            $table->timestamp('transcription_started_at')->nullable()->after('audio_extraction_completed_at')->comment('When transcription started');
            $table->timestamp('transcription_completed_at')->nullable()->after('transcription_started_at')->comment('When transcription completed');
            $table->float('audio_extraction_duration_seconds')->nullable()->after('transcription_completed_at')->comment('Duration of audio extraction in seconds');
            $table->float('transcription_duration_seconds')->nullable()->after('audio_extraction_duration_seconds')->comment('Duration of transcription in seconds');
            $table->float('total_processing_duration_seconds')->nullable()->after('transcription_duration_seconds')->comment('Total duration from start to finish');
            $table->integer('audio_file_size')->nullable()->after('total_processing_duration_seconds')->comment('Size of extracted audio in bytes');
            $table->float('audio_duration_seconds')->nullable()->after('audio_file_size')->comment('Duration of audio file in seconds');
            $table->integer('progress_percentage')->default(0)->after('audio_duration_seconds')->comment('Estimated progress percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcription_logs', function (Blueprint $table) {
            $table->dropColumn([
                'audio_extraction_started_at',
                'audio_extraction_completed_at',
                'transcription_started_at',
                'transcription_completed_at',
                'audio_extraction_duration_seconds',
                'transcription_duration_seconds',
                'total_processing_duration_seconds',
                'audio_file_size',
                'audio_duration_seconds',
                'progress_percentage'
            ]);
        });
    }
}; 