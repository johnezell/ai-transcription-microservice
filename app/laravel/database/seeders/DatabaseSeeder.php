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
        
        // Seed TrueFire data
        $this->call(LocalTruefireChannelSeeder::class);
        $this->call(LocalTruefireCourseSeeder::class);
        $this->call(LocalTruefireSegmentSeeder::class);
        
        // Seed music terms (may require table creation first)
        $this->call(MusicTermSeeder::class);
    }
}
