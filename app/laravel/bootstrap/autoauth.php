<?php

// This file is used to automatically create and authenticate as the default user
// Intended for development/testing purposes only

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

// Create or retrieve the default user
$user = User::firstOrCreate(
    ['email' => 'johne@truefirestudios.com'],
    [
        'name' => 'John Ezell',
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
    ]
);

// Log in as the default user if not already authenticated
if (!Auth::check()) {
    Auth::login($user);
} 