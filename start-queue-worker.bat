@echo off
echo Starting single queue worker in Laravel container...
echo.
echo This will run a queue worker to process background jobs.
echo Press Ctrl+C to stop the worker.
echo.
docker-compose exec laravel php artisan queue:work --queue=downloads --sleep=3 --tries=3 --timeout=300 --memory=256