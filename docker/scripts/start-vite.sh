#!/bin/bash

# Script to start Vite dev server with proper environment setup
set -e

echo "=== Starting Vite dev server ==="
echo "Working directory: $(pwd)"
echo "Node version: $(node --version)"
echo "NPM version: $(npm --version)"
echo "PATH: $PATH"

# Change to the correct directory
cd /var/www

# Check if package.json exists
if [ ! -f "package.json" ]; then
    echo "ERROR: package.json not found in /var/www"
    exit 1
fi

echo "Found package.json"

# Check if node_modules exists and has vite
if [ ! -d "node_modules" ] || [ ! -f "node_modules/.bin/vite" ]; then
    echo "Node modules not found or vite missing. Installing dependencies..."
    npm install
    
    # Check again after install
    if [ ! -f "node_modules/.bin/vite" ]; then
        echo "ERROR: Still cannot find vite executable after npm install"
        echo "Listing node_modules/.bin contents:"
        ls -la node_modules/.bin/ || echo "node_modules/.bin directory not found"
        exit 1
    fi
fi

# Verify vite is accessible
echo "Checking vite accessibility..."
ls -la node_modules/.bin/vite
echo "Vite executable found and accessible"

# Check if vite command works
echo "Testing vite command..."
./node_modules/.bin/vite --version || echo "Warning: Could not get vite version"

# Start vite dev server
echo "Starting vite dev server on 0.0.0.0:5173..."
exec npm run dev 