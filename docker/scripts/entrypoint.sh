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

# Start php-fpm in the background
echo "Starting PHP-FPM..."
php-fpm -D

# Start nginx in the background
echo "Starting nginx..."
nginx

# Ensure supervisor directories exist
echo "Creating supervisor directories..."
mkdir -p /var/run/supervisor
mkdir -p /var/log/supervisor

# Check supervisor configuration
echo "Checking supervisor configuration..."
if [ -f "/etc/supervisor/conf.d/supervisord.conf" ]; then
    echo "Supervisor config found: /etc/supervisor/conf.d/supervisord.conf"
    cat /etc/supervisor/conf.d/supervisord.conf | head -20
else
    echo "ERROR: Supervisor config not found!"
    exit 1
fi

# Start supervisor for queue workers
echo "Starting supervisor for queue workers..."
echo "Command: /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf"

# Try to start supervisor with debugging
if /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf; then
    echo "✅ Supervisor started successfully"
    # Give supervisor a moment to start
    sleep 3
    # Check if supervisor is running
    if supervisorctl status; then
        echo "✅ Supervisor status check successful"
    else
        echo "⚠️ Supervisor status check failed, trying to start workers manually as fallback"
        # Fallback: start workers manually
        php /var/www/artisan queue:work --queue=audio-extraction-high,audio-extraction,audio-extraction-low,default --sleep=3 --tries=3 &
        php /var/www/artisan queue:work --queue=transcription-high,transcription,transcription-low --sleep=3 --tries=3 &
    fi
else
    echo "❌ Supervisor failed to start, starting workers manually as fallback"
    # Fallback: start workers manually in background
    php /var/www/artisan queue:work --queue=audio-extraction-high,audio-extraction,audio-extraction-low,default --sleep=3 --tries=3 &
    php /var/www/artisan queue:work --queue=transcription-high,transcription,transcription-low --sleep=3 --tries=3 &
fi

# Keep container running
echo "=== Container startup complete ==="
echo "Entering daemon mode..."

# Use tail to keep the container running
tail -f /dev/null 