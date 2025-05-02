<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DefaultUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default testing user if it doesn't exist
        User::firstOrCreate(
            ['email' => 'johne@truefirestudios.com'],
            [
                'name' => 'John Ezell',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
    }
} 