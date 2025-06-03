# D: Drive Video Download Configuration Guide

## Overview

This guide documents the D: drive configuration implemented for the AI Transcription Microservice to store TrueFire video downloads on the D: drive instead of local storage within the Docker container.

## Configuration Summary

### What Was Implemented

1. **Docker Volume Mapping**: D: drive mounted to `/mnt/d_drive` in Laravel container
2. **Laravel Filesystem Configuration**: New `d_drive` disk configured as default
3. **Environment Variables**: Updated to use D: drive paths
4. **Download Job Updates**: Modified to use default storage disk instead of hardcoded 'local'

### File Locations

- **Docker Configuration**: [`docker-compose.yml`](docker-compose.yml:17)
- **Laravel Filesystem Config**: [`app/laravel/config/filesystems.php`](app/laravel/config/filesystems.php:72-78)
- **Environment Variables**: [`app/laravel/.env`](app/laravel/.env:27-28)
- **Download Job**: [`app/laravel/app/Jobs/DownloadTruefireSegmentV2.php`](app/laravel/app/Jobs/DownloadTruefireSegmentV2.php)

## Detailed Configuration

### 1. Docker Compose Volume Mapping

```yaml
# docker-compose.yml
services:
  laravel:
    volumes:
      - D:/ai-transcription-downloads:/mnt/d_drive
    environment:
      - FILESYSTEM_DISK=d_drive
      - D_DRIVE_PATH=/mnt/d_drive
```

### 2. Laravel Filesystem Configuration

```php
// config/filesystems.php
'default' => env('FILESYSTEM_DISK', 'local'),

'disks' => [
    'd_drive' => [
        'driver' => 'local',
        'root' => env('D_DRIVE_PATH', '/mnt/d_drive'),
        'serve' => true,
        'throw' => false,
        'report' => false,
    ],
]
```

### 3. Environment Variables

```env
# .env
FILESYSTEM_DISK=d_drive
D_DRIVE_PATH=/mnt/d_drive
```

### 4. Download Job Updates

The download job was updated to use Laravel's default storage disk instead of hardcoded 'local' disk:

```php
// Before
Storage::disk('local')->put($filePath, $content);

// After  
Storage::put($filePath, $content);
```

## Directory Structure

When videos are downloaded, they will be stored in the following structure:

```
D:/ai-transcription-downloads/
└── truefire-courses/
    ├── course-123/
    │   ├── segment-456.mp4
    │   ├── segment-789.mp4
    │   └── ...
    ├── course-124/
    │   └── ...
    └── ...
```

## Verification Tests

### Test Results Summary

All verification tests passed successfully:

1. **✅ Docker Container Access**: Laravel container can access `/mnt/d_drive`
2. **✅ Filesystem Configuration**: `d_drive` disk properly configured
3. **✅ Storage Operations**: File read/write operations work correctly
4. **✅ Directory Creation**: Course directories can be created
5. **✅ Download Job Integration**: Job uses D: drive storage
6. **✅ Configuration Validation**: All config files have valid syntax

### Test Scripts Created

- [`test-d-drive-configuration.php`](app/laravel/test-d-drive-configuration.php) - Comprehensive D: drive testing
- [`test-download-job-d-drive.php`](app/laravel/test-download-job-d-drive.php) - Download job integration testing

## Usage

### Starting the System

1. Ensure D: drive exists and has sufficient space
2. Start Docker containers:
   ```bash
   docker-compose up -d
   ```

### Triggering Video Downloads

Video downloads will automatically use the D: drive when triggered through:
- TrueFire course management interface
- Download job queue processing
- API endpoints for video downloads

### Monitoring Downloads

- Files will appear in `D:/ai-transcription-downloads/truefire-courses/`
- Laravel logs will show D: drive paths: `/mnt/d_drive/truefire-courses/...`
- Storage operations use the default `d_drive` disk

## Troubleshooting

### Common Issues

1. **Permission Errors**
   - Ensure D: drive is accessible to Docker
   - Check Windows file sharing settings for Docker

2. **Disk Space Issues**
   - Monitor D: drive space usage
   - Video files can be large (100MB+ each)

3. **Mount Point Issues**
   - Verify Docker volume mapping is active
   - Check container can access `/mnt/d_drive`

### Diagnostic Commands

```bash
# Check container can access D: drive
docker exec aws-transcription-laravel ls -la /mnt/d_drive

# Test Laravel storage configuration
docker exec aws-transcription-laravel php test-d-drive-configuration.php

# Test download job integration
docker exec aws-transcription-laravel php test-download-job-d-drive.php

# Check Laravel configuration
docker exec aws-transcription-laravel php artisan config:show filesystems
```

## Reverting to Local Storage

If you need to revert to local storage:

### 1. Update Environment Variables

```env
# .env
FILESYSTEM_DISK=local
# Remove or comment out D_DRIVE_PATH
```

### 2. Update Docker Compose (Optional)

Remove or comment out the D: drive volume mapping:

```yaml
# docker-compose.yml
volumes:
  # - D:/ai-transcription-downloads:/mnt/d_drive
```

### 3. Update Download Job (If Needed)

If reverting to a specific disk, update the job to use:

```php
Storage::disk('local')->put($filePath, $content);
```

### 4. Restart Containers

```bash
docker-compose down
docker-compose up -d
```

## Performance Considerations

### Benefits of D: Drive Storage

1. **Separation of Concerns**: Video files separate from application files
2. **Storage Management**: Easier to manage large video file storage
3. **Backup Strategy**: Can backup D: drive independently
4. **Disk Space**: Prevents filling up system drive

### Monitoring

- Monitor D: drive disk usage regularly
- Consider implementing automated cleanup of old downloads
- Set up alerts for low disk space

## Security Considerations

1. **File Permissions**: D: drive files accessible to Docker containers
2. **Access Control**: Ensure proper Windows file permissions
3. **Backup**: Include D: drive in backup strategy
4. **Cleanup**: Implement retention policies for downloaded videos

## Maintenance

### Regular Tasks

1. **Disk Space Monitoring**: Check D: drive usage
2. **Log Review**: Monitor Laravel logs for storage errors
3. **Performance Monitoring**: Watch for slow storage operations
4. **Cleanup**: Remove old or unnecessary video files

### Backup Strategy

1. **Video Files**: Backup `D:/ai-transcription-downloads/`
2. **Configuration**: Backup Docker and Laravel config files
3. **Database**: Backup course and segment metadata

## Support

For issues with the D: drive configuration:

1. Check the troubleshooting section above
2. Run the diagnostic test scripts
3. Review Docker and Laravel logs
4. Verify Windows file sharing and permissions

---

**Last Updated**: June 3, 2025  
**Configuration Version**: 1.0  
**Tested With**: Docker Compose, Laravel 11, Windows 11