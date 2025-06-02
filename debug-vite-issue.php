<?php
// Debug script to check Vite configuration and environment

echo "=== Vite Debug Information ===\n";
echo "APP_ENV: " . (getenv('APP_ENV') ?: 'not set') . "\n";
echo "APP_DEBUG: " . (getenv('APP_DEBUG') ?: 'not set') . "\n";

// Check if public/build directory exists (where built assets should be)
$buildDir = '/var/www/public/build';
echo "Build directory exists: " . (is_dir($buildDir) ? 'YES' : 'NO') . "\n";

if (is_dir($buildDir)) {
    $files = scandir($buildDir);
    echo "Build directory contents: " . implode(', ', array_filter($files, fn($f) => $f !== '.' && $f !== '..')) . "\n";
} else {
    echo "Build directory not found at: $buildDir\n";
}

// Check if manifest.json exists (Vite creates this during build)
$manifestPath = '/var/www/public/build/manifest.json';
echo "Vite manifest exists: " . (file_exists($manifestPath) ? 'YES' : 'NO') . "\n";

// Check if node_modules exists
$nodeModules = '/var/www/node_modules';
echo "Node modules installed: " . (is_dir($nodeModules) ? 'YES' : 'NO') . "\n";

echo "=== End Debug Info ===\n";