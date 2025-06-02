#!/bin/bash

# Entrypoint script for Laravel container
set -e

echo "=== Laravel Container Starting ==="

# Change to the application directory
cd /var/www

# Check if package.json exists and install dependencies if needed
if [ -f "package.json" ]; then
    echo "Found package.json, checking npm dependencies..."
    
    # Install npm dependencies if node_modules doesn't exist or vite is missing
    if [ ! -d "node_modules" ] || [ ! -f "node_modules/.bin/vite" ]; then
        echo "Installing npm dependencies..."
        npm install
    else
        echo "npm dependencies already installed"
    fi
else
    echo "No package.json found, skipping npm install"
fi

# Start supervisor
echo "Starting supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf 