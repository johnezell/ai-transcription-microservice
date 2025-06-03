# S3 Download 400 Bad Request Error - Fix Implementation

## Problem Summary

The S3 download functionality was failing with 400 Bad Request errors due to AWS credentials configuration issues in the Docker environment. The root causes were:

1. **AWS Credentials Configuration**: The Segment model's `getSignedUrl()` method created S3Client with null credentials because:
   - `.env` had empty `AWS_ACCESS_KEY_ID=` and `AWS_SECRET_ACCESS_KEY=`
   - Docker containers didn't have access to mounted AWS credentials at `/mnt/aws_creds_mounted/`

2. **S3 Bucket Configuration Mismatch**:
   - Segment model hardcoded: `'Bucket' => 'tfstream'`
   - `.env` configured: `AWS_BUCKET=aws-transcription-data-542876199144-us-east-1`

3. **Manual S3Client Creation**: The code bypassed Laravel's built-in S3 disk configuration

## Implemented Fixes

### 1. Updated Segment Model (`app/Models/Segment.php`)

**Key Changes:**
- Replaced manual S3Client creation with Laravel's Storage facade
- Added AWS credentials validation before URL generation
- Used `Storage::build()` to create tfstream-specific S3 disk
- Added comprehensive error logging and debugging information
- Implemented proper credential fallback (explicit keys → AWS profile → credential files)

**New Implementation:**
```php
public function getSignedUrl($expirationSeconds = 604800)
{
    try {
        // Validate AWS credentials are available
        $this->validateAwsCredentials();
        
        // Use Laravel's S3 disk configuration for tfstream bucket
        $tfstreamDisk = $this->getTfstreamS3Disk();
        
        // Generate temporary URL using Laravel's Storage facade
        $temporaryUrl = $tfstreamDisk->temporaryUrl(
            $video,
            now()->addSeconds($expirationSeconds)
        );
        
        return $temporaryUrl;
    } catch (\Exception $e) {
        // Enhanced error logging with debugging information
        throw new \Exception("Failed to generate S3 signed URL: " . $e->getMessage());
    }
}
```

### 2. Enhanced Filesystems Configuration (`config/filesystems.php`)

**Key Changes:**
- Added support for AWS profile-based authentication
- Configured custom credential file paths for Docker environment
- Enhanced credential resolution with fallback options

**New S3 Configuration:**
```php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID', null),
    'secret' => env('AWS_SECRET_ACCESS_KEY', null),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'bucket' => env('AWS_BUCKET'),
    'profile' => env('AWS_PROFILE', 'tfs-shared-services'),
    'credentials' => [
        'profile' => env('AWS_PROFILE', 'tfs-shared-services'),
        'filename' => env('AWS_SHARED_CREDENTIALS_FILE', '/mnt/aws_creds_mounted/credentials'),
        'config_filename' => env('AWS_CONFIG_FILE', '/mnt/aws_creds_mounted/config'),
    ],
    // ... other options
],
```

### 3. Updated Docker Configuration (`docker-compose.yml`)

**Key Changes:**
- Added AWS credentials volume mounting
- Added AWS environment variables for proper credential resolution

**New Configuration:**
```yaml
laravel:
  volumes:
    - ~/.aws:/mnt/aws_creds_mounted:ro  # Mount AWS credentials
  environment:
    - AWS_PROFILE=tfs-shared-services
    - AWS_SHARED_CREDENTIALS_FILE=/mnt/aws_creds_mounted/credentials
    - AWS_CONFIG_FILE=/mnt/aws_creds_mounted/config
    - AWS_DEFAULT_REGION=us-east-1
    - AWS_BUCKET=aws-transcription-data-542876199144-us-east-1
```

### 4. Enhanced DownloadTruefireSegmentV2 Job

**Key Changes:**
- Added specific handling for 400 Bad Request errors
- Enhanced error logging for authentication issues
- Added debugging information for troubleshooting

## Credential Resolution Strategy

The fix implements a multi-tier credential resolution strategy:

1. **Explicit Credentials**: Check for `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` in `.env`
2. **AWS Profile**: Use `AWS_PROFILE=tfs-shared-services` with mounted credential files
3. **Credential Files**: Look for credentials at `/mnt/aws_creds_mounted/credentials`
4. **Validation**: Validate credential availability before attempting S3 operations

## Testing and Validation

### 1. Test Script Created

A comprehensive test script (`test-s3-signed-url.php`) was created to validate:
- AWS configuration
- Credential file accessibility
- S3 disk creation
- Signed URL generation
- URL structure validation

### 2. Running the Test

```bash
# In Docker container
docker exec -it aws-transcription-laravel php test-s3-signed-url.php
```

### 3. Expected Test Output

The test should show:
- ✓ AWS credentials properly configured
- ✓ Credential files accessible
- ✓ S3 disks created successfully
- ✓ Signed URLs generated with proper structure
- ✓ URLs contain tfstream bucket and expected S3 keys

## Deployment Steps

1. **Restart Docker Containers** (to apply volume mounting):
   ```bash
   docker-compose down
   docker-compose up -d
   ```

2. **Verify AWS Credentials** are accessible in container:
   ```bash
   docker exec -it aws-transcription-laravel ls -la /mnt/aws_creds_mounted/
   ```

3. **Run Test Script** to validate configuration:
   ```bash
   docker exec -it aws-transcription-laravel php test-s3-signed-url.php
   ```

4. **Monitor Logs** for any remaining credential issues:
   ```bash
   docker logs aws-transcription-laravel -f
   ```

## Benefits of This Fix

1. **Uses Laravel Best Practices**: Leverages Laravel's built-in S3 disk configuration
2. **Proper Credential Management**: Supports multiple credential resolution methods
3. **Better Error Handling**: Comprehensive validation and error reporting
4. **Maintainable Code**: Removes hardcoded values and manual S3Client creation
5. **Docker-Friendly**: Properly configured for containerized environments
6. **Backward Compatible**: Maintains existing functionality while fixing issues

## Troubleshooting

If issues persist:

1. **Check AWS Credentials**:
   ```bash
   docker exec -it aws-transcription-laravel cat /mnt/aws_creds_mounted/credentials
   ```

2. **Verify Profile Configuration**:
   ```bash
   docker exec -it aws-transcription-laravel cat /mnt/aws_creds_mounted/config
   ```

3. **Test AWS CLI Access** (if available):
   ```bash
   docker exec -it aws-transcription-laravel aws s3 ls s3://tfstream/ --profile tfs-shared-services
   ```

4. **Check Laravel Logs**:
   ```bash
   tail -f app/laravel/storage/logs/laravel.log
   ```

The fix addresses all identified root causes and provides a robust, maintainable solution for S3 signed URL generation in the Docker environment.