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


# Application cache clear (optional, but good practice before migrate/start)
# Consider running these if your deployment process doesn't handle them elsewhere.
# php artisan optimize:clear
# php artisan config:cache # Re-cache config after env vars are set
# php artisan route:cache
# php artisan view:cache

echo "Running database migrations..."
# Ensure we are in the Laravel directory (WORKDIR should handle this, but for safety)
cd /var/www
php artisan migrate --force
echo "Database migrations completed."

# The init-db.sh script call is removed as migrations are now handled here.
# If init-db.sh performed other critical setup, those actions need to be 
# incorporated here or run separately if still needed.
# /usr/local/bin/init-db.sh &

echo "Starting supervisor..."
# Start supervisor (which will start nginx and php-fpm)
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf 