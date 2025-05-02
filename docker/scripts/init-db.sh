#!/bin/bash

# This script handles database initialization with retries
# It's designed to be run in the background by the entrypoint script

MAX_RETRIES=30
RETRY_INTERVAL=5

echo "Starting database initialization..."

for i in $(seq 1 $MAX_RETRIES); do
    echo "Attempt $i of $MAX_RETRIES to connect to MySQL..."
    
    if php artisan migrate --seed --force; then
        echo "Database migration completed successfully!"
        exit 0
    else
        echo "MySQL not ready yet. Waiting $RETRY_INTERVAL seconds before retry..."
        sleep $RETRY_INTERVAL
    fi
done

echo "Failed to connect to MySQL after $MAX_RETRIES attempts."
exit 1 