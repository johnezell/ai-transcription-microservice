#!/bin/bash
set -e

echo "Starting Laravel application..."

# Create storage directories if they don't exist
mkdir -p /var/www/storage/app/public/s3/jobs
mkdir -p /var/www/bootstrap/cache

# Set the correct permissions
# Original Dockerfile had: chmod -R 777 /var/www/storage & /var/www/bootstrap/cache
# Ensuring www-data owns everything first, then setting permissions.
chown -R www-data:www-data /var/www
chmod -R 777 /var/www/storage
chmod -R 777 /var/www/bootstrap/cache

# Ensure we are in the Laravel directory for artisan commands
cd /var/www

echo "Clearing and caching Laravel optimizations..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "Laravel optimizations completed."

echo "Running database migrations..."
# Ensure we are in the Laravel directory (WORKDIR should handle this, but for safety)
php artisan migrate --force
echo "Database migrations completed."

# The init-db.sh script call is removed as migrations are now handled here.
# If init-db.sh performed other critical setup, those actions need to be 
# incorporated here or run separately if still needed.
# /usr/local/bin/init-db.sh &

echo "Starting supervisor..."
# Start supervisor (which will start nginx and php-fpm)
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf 