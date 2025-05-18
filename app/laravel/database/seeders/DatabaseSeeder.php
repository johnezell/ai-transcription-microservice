<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create a default test user
        $this->call(DefaultUserSeeder::class);
        
        // Seed terminology (renamed from MusicTermSeeder)
        $this->call(TerminologySeeder::class);
        
        // Seed transcription presets
        $this->call(TranscriptionPresetSeeder::class);
    }
}
