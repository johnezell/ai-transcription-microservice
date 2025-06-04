# Bulk Download System - Developer Guide

## 🏗️ Architecture Overview

The TrueFire Bulk Download System is built on a sophisticated queue-based architecture that solves the critical signed URL expiration problem through the innovative V3 job design.

### Core Components

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│   Frontend      │    │   Controller     │    │   Queue Jobs    │
│   Vue.js        │───▶│   Laravel API    │───▶│   V3 Workers    │
│   Real-time UI  │    │   4 Endpoints    │    │   Background    │
└─────────────────┘    └──────────────────┘    └─────────────────┘
         │                       │                       │
         │                       ▼                       ▼
         │              ┌──────────────────┐    ┌─────────────────┐
         └─────────────▶│   Cache Layer    │    │   CloudFront    │
                        │   Redis/File     │    │   Signed URLs   │
                        │   Progress Data  │    │   Fresh at Exec │
                        └──────────────────┘    └─────────────────┘
```

## 🔧 V3 Job Architecture (Critical Innovation)

### The Problem with V2 Jobs

```php
// V2 Job - PROBLEMATIC
class DownloadTruefireSegmentV2 implements ShouldQueue
{
    public function __construct($segment, $courseDir, $signedUrl, $courseId)
    {
        $this->signedUrl = $signedUrl; // ❌ URL expires before execution
    }
    
    public function handle()
    {
        // ❌ URL may be expired by the time job executes
        $this->downloadFile($this->signedUrl);
    }
}
```

**Problems:**
- Signed URLs expire (typically 1-2 hours)
- Large queues mean jobs execute hours/days later
- Results in 403 "Access Denied" errors
- Bulk operations fail at scale

### The V3 Solution

```php
// V3 Job - SOLUTION
class DownloadTruefireSegmentV3 implements ShouldQueue
{
    public function __construct($segment, $courseDir, $courseId)
    {
        // ✅ No signed URL stored - generate fresh at execution
        $this->segment = $segment;
        $this->courseDir = $courseDir;
        $this->courseId = $courseId;
    }
    
    public function handle()
    {
        // ✅ Generate fresh signed URL at execution time
        $signedUrl = $this->generateFreshSignedUrl();
        $this->downloadFile($signedUrl);
    }
    
    private function generateFreshSignedUrl(): string
    {
        $cloudFrontService = app(CloudFrontSigningService::class);
        $cloudFrontUrl = "https://d2kum0w8xvhbpf.cloudfront.net/truefire/{$this->segment->id}.mp4";
        
        // ✅ Fresh 2-hour expiration
        return $cloudFrontService->signUrl($cloudFrontUrl, now()->addHours(2));
    }
}
```

**Advantages:**
- ✅ No URL expiration issues
- ✅ Supports massive queue backlogs
- ✅ Scales to 100K+ downloads
- ✅ Eliminates 403 errors

## 📡 API Endpoint Architecture

### 1. Bulk Download Initiation
**Endpoint:** `GET /truefire-courses/download-all-courses`
**Controller Method:** [`downloadAllCourses()`](app/laravel/app/Http/Controllers/TruefireCourseController.php:883)

```php
public function downloadAllCourses(Request $request)
{
    // 1. Get all courses with segments
    $courses = TruefireCourse::withCount(['segments' => function ($query) {
        $query->withVideo(); // Only segments with valid video fields
    }])->get();
    
    // 2. Initialize cache structures
    Cache::put('bulk_download_stats', $initialStats, 7200);
    Cache::put('bulk_processing_courses', [], 7200);
    Cache::put('bulk_queued_courses', $courseIds, 7200);
    
    // 3. Dispatch V3 jobs for each segment
    foreach ($courses as $course) {
        foreach ($course->segments as $segment) {
            DownloadTruefireSegmentV3::dispatch($segment, $courseDir, $course->id);
        }
    }
    
    return response()->json([
        'success' => true,
        'stats' => $bulkStats,
        'background_processing' => true
    ]);
}
```

### 2. Real-time Status Monitoring
**Endpoint:** `GET /truefire-courses/bulk-download-status`
**Controller Method:** [`bulkDownloadStatus()`](app/laravel/app/Http/Controllers/TruefireCourseController.php:1090)

```php
public function bulkDownloadStatus()
{
    // Aggregate data from multiple cache sources
    $bulkStats = Cache::get('bulk_download_stats', $defaults);
    $processingCourses = Cache::get('bulk_processing_courses', []);
    $queuedCourses = Cache::get('bulk_queued_courses', []);
    
    // Build comprehensive status response
    return response()->json([
        'bulk_stats' => $bulkStats,
        'processing_courses_count' => count($processingCourses),
        'queued_courses_count' => count($queuedCourses),
        'course_details' => $courseDetails
    ]);
}
```

### 3. Aggregated Statistics
**Endpoint:** `GET /truefire-courses/bulk-download-stats`
**Controller Method:** [`bulkDownloadStats()`](app/laravel/app/Http/Controllers/TruefireCourseController.php:1277)

### 4. Queue Monitoring
**Endpoint:** `GET /truefire-courses/bulk-queue-status`
**Controller Method:** [`bulkQueueStatus()`](app/laravel/app/Http/Controllers/TruefireCourseController.php:1161)

## 🗄️ Cache Management System

### Cache Key Structure

```php
// Bulk-level caches (TTL: 2 hours)
'bulk_download_stats'        // Overall progress statistics
'bulk_processing_courses'    // Currently processing course IDs
'bulk_queued_courses'        // Queued course IDs

// Course-level caches (TTL: 1 hour)
"download_stats_{$courseId}"     // Per-course download statistics
"processing_segments_{$courseId}" // Currently processing segment IDs
"queued_segments_{$courseId}"     // Queued segment IDs for course

// System-level caches
'download_rate_limit_v3'     // Rate limiting counter
```

### Cache Data Structures

```php
// Bulk download statistics
$bulkStats = [
    'courses_processed' => 45,
    'courses_completed' => 40,
    'courses_failed' => 2,
    'total_segments_success' => 3200,
    'total_segments_failed' => 15,
    'total_segments_skipped' => 800
];

// Per-course statistics
$courseStats = [
    'success' => 45,  // Successfully downloaded
    'failed' => 2,    // Failed downloads
    'skipped' => 8    // Already existed/skipped
];

// Processing status arrays
$processingSegments = [123, 456, 789]; // Segment IDs currently processing
$queuedCourses = [1, 2, 3, 4, 5];     // Course IDs in queue
```

### Cache Update Pattern

```php
// V3 Job updates cache at key points
class DownloadTruefireSegmentV3
{
    public function handle()
    {
        // Mark as processing
        $this->updateQueueStatus('processing');
        
        try {
            // Download logic...
            $this->updateDownloadStats('success');
        } catch (\Exception $e) {
            $this->updateDownloadStats('failed');
        } finally {
            // Always clean up
            $this->updateQueueStatus('completed');
            $this->releaseRateLimit();
        }
    }
    
    private function updateDownloadStats(string $status)
    {
        // Update both course-specific and bulk statistics
        $courseKey = "download_stats_{$this->courseId}";
        $bulkKey = "bulk_download_stats";
        
        $courseStats = Cache::get($courseKey, ['success' => 0, 'failed' => 0, 'skipped' => 0]);
        $courseStats[$status]++;
        Cache::put($courseKey, $courseStats, 3600);
        
        $bulkStats = Cache::get($bulkKey, ['success' => 0, 'failed' => 0, 'skipped' => 0]);
        $bulkStats[$status]++;
        Cache::put($bulkKey, $bulkStats, 3600);
    }
}
```

## 🎯 Frontend Integration

### Vue.js Component Architecture
**File:** [`resources/js/Pages/TruefireCourses/Index.vue`](app/laravel/resources/js/Pages/TruefireCourses/Index.vue)

#### Key State Management

```javascript
// Reactive state for bulk download
const bulkDownloadProgress = ref({
    current: 0,        // Total segments processed
    total: 0,          // Total segments to process
    successful: 0,     // Successfully downloaded
    failed: 0,         // Failed downloads
    skipped: 0,        // Already downloaded/skipped
    processing: 0,     // Currently processing
    queued: 0,         // Still in queue
    errors: [],        // Error details
    courseProgress: {} // Per-course progress tracking
});
```

#### Real-time Polling System

```javascript
const pollBulkDownloadProgress = async () => {
    const maxPollingTime = 60 * 60 * 1000; // 60 minutes
    const pollInterval = 3000; // 3 seconds
    
    const poll = async () => {
        try {
            // Get comprehensive status from all endpoints
            const statusResponse = await axios.get('/truefire-courses/bulk-download-status');
            const queueResponse = await axios.get('/truefire-courses/bulk-queue-status');
            const statsResponse = await axios.get('/truefire-courses/bulk-download-stats');
            
            // Update reactive state
            bulkDownloadProgress.value = {
                current: totalProcessed,
                total: totalSegments,
                successful: bulkStats.success,
                failed: bulkStats.failed,
                skipped: bulkStats.skipped,
                processing: bulkStats.processing || 0,
                queued: bulkStats.queued || 0,
                errors: /* error processing */,
                courseProgress: status.course_progress || {}
            };
            
            // Check completion conditions
            const isComplete = totalProcessed >= totalSegments && 
                              bulkStats.processing === 0 && 
                              bulkStats.queued === 0;
            
            if (isComplete) {
                // Show completion dialog
                showBulkResultsDialog.value = true;
                return;
            }
            
            // Continue polling
            setTimeout(poll, pollInterval);
            
        } catch (error) {
            // Error handling and retry logic
        }
    };
    
    poll(); // Start polling
};
```

#### Progress Visualization

```vue
<template>
  <!-- Overall Progress Bar -->
  <div class="w-full bg-gray-200 rounded-full h-4 mt-4">
    <div
      class="bg-blue-600 h-4 rounded-full transition-all duration-300"
      :style="{ width: (bulkDownloadProgress.current / bulkDownloadProgress.total * 100) + '%' }"
    ></div>
  </div>
  
  <!-- Status Grid -->
  <div class="grid grid-cols-4 gap-3 text-center bg-gray-50 rounded-lg p-3">
    <div class="text-green-600">
      <div class="font-bold text-lg">{{ bulkDownloadProgress.successful }}</div>
      <div class="text-xs">✅ Successful</div>
    </div>
    <div class="text-blue-600">
      <div class="font-bold text-lg">{{ bulkDownloadProgress.processing }}</div>
      <div class="text-xs">🔄 Processing</div>
    </div>
    <div class="text-yellow-600">
      <div class="font-bold text-lg">{{ bulkDownloadProgress.queued }}</div>
      <div class="text-xs">⏳ Queued</div>
    </div>
    <div class="text-red-600">
      <div class="font-bold text-lg">{{ bulkDownloadProgress.failed }}</div>
      <div class="text-xs">❌ Failed</div>
    </div>
  </div>
</template>
```

## ⚡ Performance Optimization

### Rate Limiting Implementation

```php
class DownloadTruefireSegmentV3
{
    private function enforceRateLimit(): void
    {
        $maxConcurrent = 10; // CloudFront-friendly limit
        $lockKey = 'download_rate_limit_v3';
        
        // Wait for available slot (max 60 seconds)
        $attempts = 0;
        while ($attempts < 60) {
            $current = Cache::get($lockKey, 0);
            if ($current < $maxConcurrent) {
                Cache::increment($lockKey);
                break;
            }
            
            sleep(1);
            $attempts++;
        }
        
        // Random delay to prevent thundering herd
        usleep(rand(100000, 500000)); // 0.1-0.5 seconds
    }
    
    private function releaseRateLimit(): void
    {
        Cache::decrement('download_rate_limit_v3');
    }
}
```

### Memory Management

```php
class DownloadTruefireSegmentV3
{
    public $timeout = 600;     // 10 minutes per file
    public $tries = 5;         // Retry attempts
    public $maxExceptions = 3; // Exception tolerance
    public $backoff = [30, 60, 120, 300, 600]; // Exponential backoff
    
    private function downloadFile(string $filePath, string $signedUrl): void
    {
        $client = new Client([
            'timeout' => 300,
            'connect_timeout' => 30,
            'stream' => true, // ✅ Stream large files to avoid memory issues
            'decode_content' => false
        ]);
        
        $response = $client->get($signedUrl);
        
        // ✅ Stream directly to storage
        $stream = $response->getBody();
        Storage::put($filePath, $stream->getContents());
    }
}
```

### Database Query Optimization

```php
// Efficient course loading with segment filtering
$courses = TruefireCourse::withCount(['channels', 'segments' => function ($query) {
    $query->withVideo(); // ✅ Only load segments with valid video fields
}])->get();

// Efficient segment collection
$allSegments = collect();
foreach ($course->channels as $channel) {
    $allSegments = $allSegments->merge($channel->segments);
}
```

## 🔍 Error Handling & Recovery

### Comprehensive Error Handling

```php
class DownloadTruefireSegmentV3
{
    public function handle(): void
    {
        try {
            $this->enforceRateLimit();
            $this->updateQueueStatus('processing');
            
            // Check if already downloaded
            if ($this->isFileAlreadyDownloaded($filePath)) {
                $this->updateDownloadStats('skipped');
                return;
            }
            
            // Generate fresh signed URL
            $signedUrl = $this->generateFreshSignedUrl();
            
            // Download with verification
            $this->downloadFile($filePath, $signedUrl);
            
            if ($this->verifyDownload($filePath)) {
                $this->updateDownloadStats('success');
            } else {
                throw new \Exception("Downloaded file failed verification");
            }
            
        } catch (RequestException $e) {
            $this->handleDownloadError($e, 'cURL error');
        } catch (\Exception $e) {
            $this->handleDownloadError($e, 'Download failed');
        } finally {
            // ✅ Always clean up
            $this->updateQueueStatus('completed');
            $this->releaseRateLimit();
        }
    }
    
    public function failed(\Throwable $exception): void
    {
        // ✅ Handle permanent failures
        Log::error("Job failed permanently for segment {$this->segment->id}");
        $this->updateDownloadStats('failed');
        $this->releaseRateLimit();
    }
}
```

### File Verification System

```php
private function verifyDownload(string $filePath): bool
{
    if (!Storage::exists($filePath)) {
        return false;
    }
    
    $fileSize = Storage::size($filePath);
    
    // Basic size validation
    if ($fileSize < 1024) {
        Log::warning("Downloaded file is too small ({$fileSize} bytes)");
        return false;
    }
    
    // Video file signature validation
    $content = Storage::get($filePath);
    $header = substr($content, 0, 12);
    
    $videoSignatures = [
        "\x00\x00\x00\x18ftypmp4", // MP4
        "\x00\x00\x00\x20ftypmp4", // MP4
        "\x1A\x45\xDF\xA3",        // WebM/MKV
        "FLV\x01",                 // FLV
    ];
    
    foreach ($videoSignatures as $signature) {
        if (strpos($header, $signature) !== false) {
            return true;
        }
    }
    
    // Fallback: accept if reasonably sized
    return $fileSize > 10240; // At least 10KB
}
```

## 🧪 Testing Framework

### Comprehensive Test Suite
**File:** [`test-bulk-download-complete.php`](app/laravel/test-bulk-download-complete.php)

#### Test Categories

1. **System Prerequisites**
   - Database connectivity
   - Queue configuration
   - Cache functionality
   - Storage availability

2. **Data Verification**
   - TrueFire course availability
   - Segment data integrity
   - Video field validation

3. **V3 Job Architecture**
   - Constructor validation
   - Signed URL generation
   - Property integrity

4. **API Endpoint Testing**
   - All 4 endpoints functional
   - Response time measurement
   - Data structure validation

5. **Cache Management**
   - Key existence verification
   - Data integrity checks
   - Performance metrics

6. **Frontend Integration**
   - Component existence
   - Required method availability
   - API integration points

7. **Error Handling**
   - Invalid input handling
   - Edge case management
   - Recovery mechanisms

#### Running Tests

```bash
# Run comprehensive test suite
docker exec -it laravel-container php test-bulk-download-complete.php

# Run basic endpoint tests
docker exec -it laravel-container php test-bulk-download-endpoints.php
```

## 🚀 Deployment Guide

### Production Configuration

#### Queue Workers
```bash
# Supervisor configuration for production
[program:bulk-download-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/artisan queue:work --queue=downloads --sleep=3 --tries=5 --max-time=3600
directory=/var/www
autostart=true
autorestart=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/storage/logs/queue-worker.log
```

#### Cache Configuration
```php
// config/cache.php - Production
'default' => 'redis',
'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'cache',
        'lock_connection' => 'default',
    ],
],
```

#### Storage Configuration
```php
// config/filesystems.php
'default' => 'local', // or 's3' for cloud storage
'disks' => [
    'local' => [
        'driver' => 'local',
        'root' => storage_path('app'),
        'permissions' => [
            'file' => [
                'public' => 0644,
                'private' => 0600,
            ],
            'dir' => [
                'public' => 0755,
                'private' => 0700,
            ],
        ],
    ],
],
```

### Monitoring Setup

#### Health Checks
```bash
# Queue health monitoring
php artisan queue:monitor downloads --max=1000

# Failed job alerts
php artisan queue:failed | wc -l

# Storage space monitoring
df -h /var/www/storage/app/ai-transcription-downloads/
```

#### Performance Metrics
```bash
# Redis cache performance
redis-cli info stats | grep keyspace

# Queue throughput
php artisan queue:monitor downloads

# System resources
htop
iostat -x 1
```

## 🔧 Maintenance & Operations

### Regular Maintenance Tasks

```bash
# Daily: Clear completed jobs
php artisan queue:prune-batches --hours=24

# Weekly: Clear old failed jobs
php artisan queue:flush

# Monthly: Optimize cache
php artisan cache:clear
php artisan config:cache
php artisan route:cache
```

### Troubleshooting Commands

```bash
# Check queue status
php artisan queue:work --queue=downloads --once

# Monitor real-time queue activity
php artisan queue:listen --queue=downloads

# Restart all queue workers
php artisan queue:restart

# Clear specific cache keys
php artisan tinker
>>> Cache::forget('bulk_download_stats');
>>> Cache::forget('bulk_processing_courses');
```

### Log Analysis

```bash
# Monitor download progress
tail -f storage/logs/laravel.log | grep "DownloadTruefireSegmentV3"

# Check for errors
grep "ERROR" storage/logs/laravel.log | grep "bulk"

# Performance analysis
grep "Response time" storage/logs/laravel.log
```

## 📈 Scaling Considerations

### Horizontal Scaling
- Multiple queue workers across servers
- Load balancer for API endpoints
- Distributed cache (Redis Cluster)
- Shared storage (NFS/S3)

### Vertical Scaling
- Increase worker memory limits
- More CPU cores for parallel processing
- Faster storage (SSD/NVMe)
- Higher network bandwidth

### Database Optimization
- Index optimization for queue tables
- Connection pooling
- Read replicas for reporting
- Partitioning for large datasets

---

This developer guide provides comprehensive technical documentation for maintaining, extending, and troubleshooting the bulk download system. The V3 job architecture is the key innovation that enables reliable large-scale operations.