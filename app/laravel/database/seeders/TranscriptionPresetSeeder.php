<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\TranscriptionPreset;

class TranscriptionPresetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if we already have presets
        if (TranscriptionPreset::count() > 0) {
            $this->command->info('Presets already exist, skipping seeding.');
            return;
        }
        
        // Create a default preset
        TranscriptionPreset::create([
            'name' => 'Standard Quality',
            'description' => 'Balanced between speed and accuracy, good for most content',
            'model' => 'medium',
            'language' => 'en', // English
            'options' => [
                'timestamps' => true,
                'diarization' => false,
                'comprehensiveTimestamps' => false,
                'temperature' => 0,
                'promptBoost' => '',
            ],
            'is_default' => true,
            'is_active' => true,
        ]);
        
        // Create high quality preset
        TranscriptionPreset::create([
            'name' => 'High Quality',
            'description' => 'Maximum accuracy for important content, but slower processing',
            'model' => 'large',
            'language' => 'en',
            'options' => [
                'timestamps' => true,
                'diarization' => true,
                'comprehensiveTimestamps' => true,
                'temperature' => 0,
                'promptBoost' => '',
            ],
            'is_default' => false,
            'is_active' => true,
        ]);
        
        // Create fast preset
        TranscriptionPreset::create([
            'name' => 'Fast Processing',
            'description' => 'Quicker transcription with lower accuracy, good for drafts',
            'model' => 'base',
            'language' => 'en',
            'options' => [
                'timestamps' => true,
                'diarization' => false,
                'comprehensiveTimestamps' => false,
                'temperature' => 0,
                'promptBoost' => '',
            ],
            'is_default' => false,
            'is_active' => true,
        ]);
        
        $this->command->info('Created 3 transcription presets.');
    }
} 