# Queue Management Guide

## Overview

This guide addresses the queue management issue where delayed jobs remain after pruning operations, particularly affecting the bulk download system.

## The Problem

### Issue Description
- When running standard Laravel queue pruning commands (`queue:prune-failed`), delayed jobs remain in the queue
- These delayed jobs are created by the retry backoff strategy in jobs like `DownloadTruefireSegmentV3`
- Users see "jobs remaining" even after attempting to clean up the queue
- Delayed jobs accumulate over time, consuming database space

### Why This Happens
1. **Delayed jobs are not "failed" jobs** - They're legitimate jobs waiting for their retry time
2. **Backoff strategy** - Jobs with exponential backoff create delayed entries with `available_at > current_timestamp`
3. **Standard pruning only removes failed jobs** - Commands like `queue:prune-failed` don't touch delayed jobs
4. **Queue workers may be stopped** - Delayed jobs never get processed if workers aren't running

## The Solution

### Understanding Job States
Jobs in Laravel queues have different states:
- **Pending**: `available_at <= now()` and `reserved_at IS NULL`
- **Delayed**: `available_at > now()` (waiting for retry time)
- **Processing**: `reserved_at IS NOT NULL` (currently being processed)
- **Failed**: Moved to `failed_jobs` table after exhausting retries

### Proper Queue Cleanup Commands

#### 1. Clear All Jobs (Including Delayed)
```bash
# Clear all jobs from default connection
php artisan queue:clear

# Clear all jobs from all queues (nuclear option)
php artisan queue:flush
```

#### 2. Clear Specific Queue Connection
```bash
# Clear jobs from database connection
php artisan queue:clear database

# Clear jobs from redis connection
php artisan queue:clear redis
```

#### 3. Clear Failed Jobs Only
```bash
# Remove failed jobs older than 24 hours
php artisan queue:prune-failed --hours=24

# Remove all failed jobs immediately
php artisan queue:prune-failed --hours=0
```

### Custom Queue Management Tools

We've created specialized tools for better queue management:

#### 1. Queue Status Checker
```bash
php queue-status-checker.php
```
**Features:**
- Shows detailed breakdown of job states
- Identifies delayed jobs and their wait times
- Provides recommendations for cleanup
- Shows cache status and rate limiting info

#### 2. Interactive Cleanup Tool
```bash
php queue-cleanup-tools.php
```
**Features:**
- Interactive menu for different cleanup options
- Clear all jobs (including delayed)
- Clear only delayed jobs
- Clear specific queues
- Complete queue reset
- Clear bulk download cache

#### 3. Queue Analysis Tool
```bash
php test-queue-management.php
```
**Features:**
- Comprehensive queue analysis
- Job type breakdown
- Command effectiveness testing
- Bulk download behavior analysis

## Bulk Download System Queue Management

### Understanding Bulk Download Jobs

The `DownloadTruefireSegmentV3` job uses:
- **Exponential backoff**: `[30, 60, 120, 300, 600]` seconds
- **Max retries**: 5 attempts
- **Rate limiting**: Max 10 concurrent downloads
- **Queue name**: `downloads`

### Common Scenarios

#### 1. Jobs Stuck Due to Rate Limiting
**Symptoms:**
- Jobs remain in "delayed" state
- Rate limit cache shows high usage

**Solution:**
```bash
# Clear rate limiting cache
php queue-cleanup-tools.php
# Select option 7: Clear bulk download cache
```

#### 2. Jobs Delayed Due to Failures
**Symptoms:**
- Multiple delayed jobs with increasing wait times
- High failure count in bulk download stats

**Solution:**
```bash
# Option 1: Clear all delayed jobs
php queue-cleanup-tools.php
# Select option 3: Clear only delayed jobs

# Option 2: Complete reset
php artisan queue:flush
```

#### 3. Queue Worker Not Running
**Symptoms:**
- Jobs accumulate but never process
- No "processing" jobs visible

**Solution:**
```bash
# Start queue worker
php artisan queue:work --queue=downloads

# Or restart existing workers
php artisan queue:restart
```

## Best Practices

### 1. Regular Monitoring
```bash
# Check queue status regularly
php queue-status-checker.php

# Monitor for delayed job accumulation
# Alert if delayed jobs > 100
```

### 2. Proper Cleanup Procedures
```bash
# Daily cleanup of old failed jobs
php artisan queue:prune-failed --hours=24

# Weekly analysis of queue health
php test-queue-management.php

# Emergency cleanup if needed
php artisan queue:flush
```

### 3. Queue Worker Management
```bash
# Ensure workers are running
php artisan queue:work --daemon --queue=downloads

# Use supervisor for production
# See docker/supervisor/queue-workers.conf
```

### 4. Bulk Download Specific
```bash
# Before starting bulk downloads
php queue-status-checker.php

# After bulk downloads complete
php queue-cleanup-tools.php
# Clear cache and check for stuck jobs
```

## Troubleshooting

### Issue: "Jobs remain after pruning"
**Cause:** Delayed jobs from retry backoff
**Solution:** Use `queue:clear` or `queue:flush` instead of `queue:prune-failed`

### Issue: "Rate limit timeout errors"
**Cause:** Too many concurrent downloads
**Solution:** Clear rate limiting cache or wait for timeout

### Issue: "Jobs never process"
**Cause:** Queue worker not running
**Solution:** Start queue worker with proper queue name

### Issue: "Database growing due to jobs"
**Cause:** Accumulation of delayed jobs
**Solution:** Regular cleanup with custom tools

## Production Recommendations

### 1. Monitoring Setup
- Monitor queue size daily
- Alert on delayed job accumulation
- Track bulk download success rates

### 2. Automated Cleanup
```bash
# Cron job for daily cleanup
0 2 * * * cd /path/to/app && php artisan queue:prune-failed --hours=24

# Weekly queue analysis
0 3 * * 0 cd /path/to/app && php test-queue-management.php > /var/log/queue-analysis.log
```

### 3. Queue Worker Configuration
```bash
# Use supervisor for reliable workers
# Configure proper memory limits and timeouts
# Monitor worker health
```

### 4. Emergency Procedures
```bash
# Complete queue reset (emergency only)
php artisan queue:flush

# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan queue:restart
```

## Files Created

### Analysis and Management Tools
- `test-queue-management.php` - Comprehensive queue analysis
- `queue-status-checker.php` - Detailed status information
- `queue-cleanup-tools.php` - Interactive cleanup utility
- `test-delayed-jobs-simulation.php` - Issue demonstration

### Documentation
- `QUEUE_MANAGEMENT_GUIDE.md` - This comprehensive guide
- Updated bulk download guides with queue management sections

## Summary

The delayed jobs issue is resolved by:
1. **Understanding the difference** between delayed and failed jobs
2. **Using proper commands** (`queue:clear`/`queue:flush` vs `queue:prune-failed`)
3. **Regular monitoring** with custom tools
4. **Proper queue worker management**
5. **Preventive maintenance** procedures

The bulk download system now has proper queue management with tools for monitoring, analysis, and cleanup.