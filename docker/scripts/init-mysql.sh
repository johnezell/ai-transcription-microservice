#!/bin/bash

# Wait for MySQL to be ready
echo "Waiting for MySQL to be ready..."
until php artisan db:monitor --timeout=60; do
    echo "MySQL not ready yet. Waiting..."
    sleep 2
done

echo "MySQL is ready! Running migrations..."

# Run migrations and seed the database
php artisan migrate:fresh --seed --force

echo "Database initialization completed!" 