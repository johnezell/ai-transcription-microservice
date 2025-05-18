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

# Test database connection before proceeding
echo "Testing database connection..."
MAX_RETRIES=5
RETRY_COUNT=0

while [ $RETRY_COUNT -lt $MAX_RETRIES ]; do
    if php artisan db:monitor --quiet; then
        echo "Database connection successful."
        break
    else
        RETRY_COUNT=$((RETRY_COUNT+1))
        if [ $RETRY_COUNT -eq $MAX_RETRIES ]; then
            echo "Failed to connect to database after $MAX_RETRIES attempts. Starting anyway..."
        else
            echo "Database connection failed. Retrying in 5 seconds... (Attempt $RETRY_COUNT/$MAX_RETRIES)"
            sleep 5
        fi
    fi
done

# First make sure the cache table exists
echo "Creating cache table if it doesn't exist..."
# Run specific migration only if table doesn't exist
php artisan migrate --path=database/migrations/0001_01_01_000001_create_cache_table.php --force || true
echo "Cache table creation completed."

echo "Clearing all Laravel caches..."
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
php artisan event:clear # Clear event cache too, just in case
echo "Laravel caches cleared."

echo "Re-caching configurations (config, route, view)..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "Laravel configurations re-cached."

echo "Running database migrations..."
# Set a reasonable timeout and don't fail the container if migrations have issues
timeout 120 php artisan migrate --force || echo "Migrations may not have completed successfully, but continuing startup..."
echo "Database migrations completed."

# Set PHP opcache configuration based on APP_ENV
echo "Setting PHP opcache configuration based on APP_ENV: $APP_ENV..."
PHP_CONF_D_PATH="/usr/local/etc/php/conf.d"
# Ensure APP_ENV is available, provide a default if not set (though it should be from .env)
: "${APP_ENV:=development}"

if [ "$APP_ENV" != "production" ] && [ "$APP_ENV" != "prod" ]; then
  echo "Using development opcache settings."
  if [ -f /usr/local/etc/php/opcache.dev.ini ]; then
    cp /usr/local/etc/php/opcache.dev.ini "$PHP_CONF_D_PATH/zz-opcache.ini"
  else
    echo "Warning: opcache.dev.ini not found."
  fi
else
  echo "Using production opcache settings."
  if [ -f /usr/local/etc/php/opcache.prod.ini ]; then
    cp /usr/local/etc/php/opcache.prod.ini "$PHP_CONF_D_PATH/zz-opcache.ini"
  else
    echo "Warning: opcache.prod.ini not found."
  fi
  # Ensure Vite's hot reload trigger file is removed in production
  if [ -f /var/www/public/hot ]; then
    echo "Removing Vite hot file in production."
    rm /var/www/public/hot
  fi
fi

echo "Starting supervisor..."
# Start supervisor (which will start nginx and php-fpm)
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf 