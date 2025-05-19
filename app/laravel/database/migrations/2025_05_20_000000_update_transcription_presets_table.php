<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transcription_presets', function (Blueprint $table) {
            // Rename 'options' to 'old_options' to preserve existing data
            $table->renameColumn('options', 'old_options');
            
            // Add new unified configuration column
            $table->json('configuration')->nullable()->after('language');
        });

        // Migrate existing data to new structure
        $presets = DB::table('transcription_presets')->get();
        foreach ($presets as $preset) {
            $oldOptions = json_decode($preset->old_options, true) ?: [];
            
            $configuration = [
                'transcription' => [
                    'initial_prompt' => $oldOptions['initial_prompt'] ?? null,
                    'temperature' => $oldOptions['temperature'] ?? 0,
                    'word_timestamps' => $oldOptions['word_timestamps'] ?? true,
                    'condition_on_previous_text' => $oldOptions['condition_on_previous_text'] ?? false,
                ],
                'audio' => [
                    'sample_rate' => '16000',
                    'channels' => '1',
                    'audio_codec' => 'pcm_s16le',
                    'noise_reduction' => false,
                    'normalize_audio' => false,
                    'volume_boost' => 0,
                ]
            ];
            
            // Copy any other existing options
            foreach ($oldOptions as $key => $value) {
                if (!in_array($key, ['initial_prompt', 'temperature', 'word_timestamps', 'condition_on_previous_text'])) {
                    $configuration['transcription'][$key] = $value;
                }
            }
            
            DB::table('transcription_presets')
                ->where('id', $preset->id)
                ->update(['configuration' => json_encode($configuration)]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transcription_presets', function (Blueprint $table) {
            // Revert to original schema
            $table->dropColumn('configuration');
            $table->renameColumn('old_options', 'options');
        });
    }
}; 