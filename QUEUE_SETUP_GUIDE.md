# Laravel Queue Setup - Single Worker Solution

## Overview
This is a simplified queue setup that runs a single queue worker within the existing Laravel container. This approach is perfect for single PC setups and avoids the complexity of multiple worker containers.

## Queue Configuration ✅
- **Queue Driver**: `database` (configured in docker-compose.yml)
- **Database**: SQLite database for job storage
- **Queue Table**: Uses the `jobs` table created by Laravel migration

## How to Start the Queue Worker

### Simple Method (Recommended)
```bash
# Use the provided batch script (Windows)
start-queue-worker.bat
```

### Manual Method
```bash
# Run a single worker inside the Laravel container
docker-compose exec laravel php artisan queue:work --queue=downloads --sleep=3 --tries=3 --timeout=300 --memory=256
```

### Worker Parameters Explained
- `--queue=downloads`: Process jobs from the 'downloads' queue (used by TrueFire downloads)
- `--sleep=3`: Wait 3 seconds between checking for new jobs
- `--tries=3`: Retry failed jobs up to 3 times
- `--timeout=300`: Maximum time (5 minutes) for a single job
- `--memory=256`: Restart worker if memory usage exceeds 256MB

## Monitoring the Queue Worker

### Check Worker Status
```bash
# View worker output in real-time (worker runs in foreground)
# Press Ctrl+C to stop the worker

# Check pending jobs in database
docker-compose exec laravel php artisan tinker
>>> DB::table('jobs')->count(); // Shows number of pending jobs
>>> DB::table('jobs')->get(); // Shows job details
```

### Queue Management Commands
```bash
# Clear all pending jobs
docker-compose exec laravel php artisan queue:flush

# Clear only failed jobs
docker-compose exec laravel php artisan queue:flush --failed

# View failed jobs
docker-compose exec laravel php artisan failed:show
```

## Verification Steps

### 1. Verify Queue Configuration
```bash
# Check that queue is set to 'database' driver
docker-compose exec laravel php debug-queue-config.php
```

### 2. Test Job Dispatch
```bash
# Dispatch a test job to verify it gets queued
docker-compose exec laravel php test-job-dispatch.php
```

### 3. Run Queue Verification Test
```bash
# Complete end-to-end test of queue system
docker-compose exec laravel php test-queue-verification.php
```

## Important Notes

### Worker Behavior
- **Single Worker**: Only one worker processes jobs at a time
- **Foreground Process**: Worker runs in the terminal and shows real-time output
- **Manual Start**: Worker must be started manually when needed
- **Graceful Stop**: Press Ctrl+C to stop the worker cleanly

### When to Run the Worker
- **Before Processing Jobs**: Start the worker before dispatching jobs that need background processing
- **During Downloads**: Keep the worker running while TrueFire course downloads are active
- **As Needed**: Start/stop the worker based on your workflow

### Performance Considerations
- **Single PC Setup**: One worker is sufficient for most single-user scenarios
- **Memory Efficient**: Uses only 256MB memory limit per worker
- **Job Timeout**: 5-minute timeout prevents stuck jobs from blocking the queue

## Troubleshooting

### Worker Not Processing Jobs
1. **Verify worker is running**: Check if the worker command is active in terminal
2. **Check queue configuration**: Run `debug-queue-config.php` to verify settings
3. **Verify database**: Ensure SQLite database is accessible and jobs table exists

### Jobs Failing
1. **Check error output**: Worker shows errors in real-time in the terminal
2. **Review failed jobs**: Use `php artisan failed:show` to see failure details
3. **Check logs**: Review Laravel logs in `storage/logs/laravel.log`

### Performance Issues
1. **Increase memory**: Change `--memory=256` to `--memory=512` if needed
2. **Increase timeout**: Change `--timeout=300` to higher value for long-running jobs
3. **Monitor system**: Watch CPU and memory usage during job processing

## Environment Configuration

### Docker Compose (Already Configured)
```yaml
environment:
  - QUEUE_CONNECTION=database
  - DB_CONNECTION=sqlite
  - DB_DATABASE=/var/www/database.sqlite
```

### Laravel .env (Already Configured)
```env
QUEUE_CONNECTION=database
```

## Quick Start Checklist

1. ✅ **Queue configured**: Database driver is already set up
2. ✅ **Jobs table exists**: Created by Laravel migration
3. 🔄 **Start worker**: Run `start-queue-worker.bat` when needed
4. 🔄 **Test system**: Use verification scripts to ensure everything works
5. 🔄 **Monitor jobs**: Watch worker output for job processing status

## Comparison: Single vs Multiple Workers

| Aspect | Single Worker (Current) | Multiple Workers (Removed) |
|--------|------------------------|----------------------------|
| **Complexity** | Simple | Complex |
| **Resource Usage** | Low | High |
| **Setup** | One command | Multiple containers |
| **Monitoring** | Easy (one terminal) | Complex (multiple logs) |
| **Suitable For** | Single PC, development | High-volume production |
| **Failure Recovery** | Manual restart | Automatic restart |

This simplified approach provides all the benefits of background job processing without the overhead of managing multiple worker containers.