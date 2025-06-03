# Single Queue Worker Setup

## Quick Start

This project now uses a simplified single queue worker approach that's perfect for single PC setups.

### 1. Start the Queue Worker
```bash
# Windows (recommended)
.\start-queue-worker.bat

# Manual command
docker-compose exec laravel php artisan queue:work --queue=downloads --sleep=3 --tries=3 --timeout=300 --memory=256
```

### 2. Test the Setup
```bash
# Run comprehensive test
docker-compose exec laravel php test-single-worker-setup.php

# Dispatch a test job
docker-compose exec laravel php test-job-dispatch.php
```

### 3. Monitor Jobs
- Worker runs in foreground and shows real-time output
- Press `Ctrl+C` to stop the worker
- Jobs are stored in SQLite database (`jobs` table)

## Key Features

✅ **Simple**: Single worker, no complex container orchestration  
✅ **Reliable**: Database-backed queue with retry logic  
✅ **Efficient**: Low memory usage (256MB limit)  
✅ **Visible**: Real-time job processing output  
✅ **Tested**: Comprehensive verification scripts  

## Files Created/Modified

- `start-queue-worker.bat` - Simple script to start the worker
- `QUEUE_SETUP_GUIDE.md` - Complete documentation
- `app/laravel/test-single-worker-setup.php` - Verification test
- Removed: `docker-compose.workers.yml`, `docker-compose.scale.yml`

## Configuration

Queue is configured to use:
- **Driver**: `database` (SQLite)
- **Queue Name**: `downloads` (for TrueFire downloads)
- **Retry Logic**: 3 attempts with exponential backoff
- **Timeout**: 5 minutes per job
- **Memory Limit**: 256MB

## Usage

1. **Start the worker** when you need to process background jobs
2. **Keep it running** during TrueFire course downloads
3. **Stop it** when not needed (saves system resources)
4. **Monitor output** to see job processing in real-time

This approach eliminates the complexity of multiple worker containers while providing reliable background job processing.