#!/bin/bash
set -e

echo "Starting Laravel application..."

# Create storage directories if they don't exist
mkdir -p /var/www/storage/app/public/s3/jobs
mkdir -p /var/www/bootstrap/cache

# Set the correct permissions
chown -R www-data:www-data /var/www/storage
chmod -R 775 /var/www/storage
chown -R www-data:www-data /var/www/bootstrap/cache
chmod -R 775 /var/www/bootstrap/cache

# Try to set up database in the background
/usr/local/bin/init-db.sh &

# Start supervisor (which will start nginx and php-fpm)
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf 