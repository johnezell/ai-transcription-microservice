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
        
        // Seed music terms
        $this->call(MusicTermSeeder::class);
    }
}
