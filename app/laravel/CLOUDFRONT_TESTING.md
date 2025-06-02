# CloudFront Signing Service Testing Guide

This guide provides comprehensive instructions for testing your CloudFront URL signing service in Laravel.

## 🚀 Quick Answer: Is an S3 File Enough?

**Yes, providing an S3 file URL is sufficient for basic testing**, but you'll need a few additional components for complete testing:

1. **Your CloudFront distribution domain** (e.g., `https://d1234567890.cloudfront.net`)
2. **The S3 object key/path** (e.g., `/uploads/videos/sample.mp4`)
3. **Valid CloudFront private key and key pair ID** (configured in your Laravel app)

## 📋 Prerequisites

Before testing, ensure you have:

- [ ] CloudFront distribution set up with trusted key groups
- [ ] Private key file placed in `storage/app/cloudfront/`
- [ ] Environment variables configured in `.env`
- [ ] Laravel application running

### Environment Configuration

Add these to your `.env` file:

```env
CLOUDFRONT_PRIVATE_KEY_PATH=/path/to/storage/app/cloudfront/pk-APKAJKYJ7CQO2ZKTVR4Q.pem
CLOUDFRONT_KEY_PAIR_ID=APKAJKYJ7CQO2ZKTVR4Q
CLOUDFRONT_REGION=us-east-1
CLOUDFRONT_DEFAULT_EXPIRATION=300
```

## 🧪 Testing Methods

### Method 1: Web Interface (Easiest)

1. **Access the test interface:**
   ```
   http://your-app.com/cloudfront-test
   ```

2. **Replace example values with your actual:**
   - CloudFront domain: `https://your-domain.cloudfront.net`
   - File path: `/your/actual/file.mp4`

3. **Test different scenarios:**
   - Single URL signing
   - Multiple URL signing
   - IP whitelisting
   - Different file types

### Method 2: Command Line Script

1. **Run the test script:**
   ```bash
   cd app/laravel
   php test-cloudfront-signing.php
   ```

2. **Edit the script to use your actual values:**
   ```php
   // Replace these with your actual values
   $cloudFrontDomain = 'https://your-domain.cloudfront.net';
   $filePath = '/your/actual/file.mp4';
   ```

### Method 3: API Testing with cURL

1. **Test configuration validation:**
   ```bash
   curl -X GET http://your-app.com/api/cloudfront/validate-config
   ```

2. **Sign a single URL:**
   ```bash
   curl -X POST http://your-app.com/api/cloudfront/sign-url \
        -H 'Content-Type: application/json' \
        -d '{
            "server": "https://your-domain.cloudfront.net",
            "file": "/your/file.mp4",
            "seconds": 3600,
            "whitelist": false
        }'
   ```

3. **Sign multiple URLs:**
   ```bash
   curl -X POST http://your-app.com/api/cloudfront/sign-multiple-urls \
        -H 'Content-Type: application/json' \
        -d '{
            "urls": [
                "https://your-domain.cloudfront.net/video1.mp4",
                "https://your-domain.cloudfront.net/video2.mp4"
            ],
            "seconds": 1800,
            "whitelist": false
        }'
   ```

### Method 4: PHPUnit Tests

1. **Run the test suite:**
   ```bash
   cd app/laravel
   php artisan test --filter CloudFront
   ```

2. **Run specific test classes:**
   ```bash
   php artisan test tests/Feature/CloudFrontSigningTest.php
   php artisan test tests/Feature/CloudFrontApiTest.php
   ```

## 🔍 What to Test

### 1. Configuration Validation
- [ ] Private key file exists and is readable
- [ ] Key pair ID is correctly configured
- [ ] Service initializes without errors

### 2. Basic URL Signing
- [ ] Single URL signing works
- [ ] Signed URL contains required parameters (Expires, Signature, Key-Pair-Id)
- [ ] Signed URL is accessible in browser
- [ ] URL expires after specified time

### 3. Multiple URL Signing
- [ ] Batch signing works for multiple URLs
- [ ] All URLs in batch are signed correctly
- [ ] Failed URLs are handled gracefully

### 4. IP Whitelisting
- [ ] IP whitelisting works for non-restricted files
- [ ] IP whitelisting is skipped for restricted files (PDF, MP3, PTB, GP5)
- [ ] Policy parameter is included when IP whitelisting is enabled

### 5. File Type Handling
Test with different file types:
- [ ] Video files (.mp4, .mov, .avi)
- [ ] Audio files (.mp3, .wav, .m4a)
- [ ] Document files (.pdf)
- [ ] Tab files (.ptb, .gp5)
- [ ] Image files (.jpg, .png)

### 6. Error Handling
- [ ] Invalid private key path
- [ ] Missing key pair ID
- [ ] Invalid CloudFront domain
- [ ] Network connectivity issues

## 📊 Expected Results

### Successful URL Signing
A signed URL should look like:
```
https://d1234567890.cloudfront.net/path/to/file.mp4?Expires=1640995200&Signature=ABC123...&Key-Pair-Id=APKAJKYJ7CQO2ZKTVR4Q
```

### With IP Whitelisting (Policy-based)
```
https://d1234567890.cloudfront.net/path/to/file.mp4?Policy=eyJ...&Signature=XYZ789...&Key-Pair-Id=APKAJKYJ7CQO2ZKTVR4Q
```

## 🐛 Troubleshooting

### Common Issues

1. **"Private key file not found"**
   - Check file path in `.env`
   - Ensure file exists in `storage/app/cloudfront/`
   - Verify file permissions

2. **"Invalid signature"**
   - Verify key pair ID matches private key
   - Check CloudFront distribution configuration
   - Ensure trusted key groups are set up

3. **"Access denied" when accessing signed URL**
   - Verify CloudFront distribution allows signed URLs
   - Check trusted key group configuration
   - Ensure private key corresponds to public key in AWS

4. **URLs expire immediately**
   - Check system time synchronization
   - Verify expiration parameter
   - Check for timezone issues

### Debug Steps

1. **Check Laravel logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

2. **Test with minimal example:**
   ```php
   $service = new CloudFrontSigningService();
   $url = $service->signUrl('https://your-domain.cloudfront.net', '/test.txt', 3600, false);
   echo $url;
   ```

3. **Verify AWS configuration:**
   - CloudFront distribution settings
   - Trusted key groups
   - Public key upload

## 📝 Test Checklist

Use this checklist to ensure comprehensive testing:

### Setup
- [ ] Environment variables configured
- [ ] Private key file in correct location
- [ ] Laravel application running
- [ ] CloudFront distribution configured

### Basic Functionality
- [ ] Configuration validation passes
- [ ] Single URL signing works
- [ ] Multiple URL signing works
- [ ] Signed URLs are accessible

### Advanced Features
- [ ] IP whitelisting works correctly
- [ ] File type restrictions work
- [ ] Custom expiration times work
- [ ] Error handling works

### API Testing
- [ ] All API endpoints respond correctly
- [ ] Input validation works
- [ ] Error responses are proper JSON
- [ ] CORS headers if needed

### Integration Testing
- [ ] Service works with your application
- [ ] URLs work in production environment
- [ ] Performance is acceptable
- [ ] Logging works correctly

## 🎯 Real-World Testing

### With Your Actual S3 Files

1. **Get your CloudFront domain:**
   ```
   https://your-actual-domain.cloudfront.net
   ```

2. **Get your S3 object key:**
   ```
   /uploads/videos/2024/01/15/your-video.mp4
   ```

3. **Test the combination:**
   ```bash
   curl -X POST http://your-app.com/api/cloudfront/sign-url \
        -H 'Content-Type: application/json' \
        -d '{
            "server": "https://your-actual-domain.cloudfront.net",
            "file": "/uploads/videos/2024/01/15/your-video.mp4",
            "seconds": 3600,
            "whitelist": false
        }'
   ```

4. **Verify the signed URL works:**
   - Copy the signed URL from the response
   - Open it in a browser or test with curl
   - Verify your content is served correctly

## 🔧 Configuration Examples

### For Development
```env
CLOUDFRONT_PRIVATE_KEY_PATH=/var/www/storage/app/cloudfront/pk-dev.pem
CLOUDFRONT_KEY_PAIR_ID=APKAJKYJ7CQO2ZKTVR4Q
CLOUDFRONT_DEFAULT_EXPIRATION=300
```

### For Production
```env
CLOUDFRONT_PRIVATE_KEY_PATH=/var/www/storage/app/cloudfront/pk-prod.pem
CLOUDFRONT_KEY_PAIR_ID=APKAJKYJ7CQO2ZKTVR4Q
CLOUDFRONT_DEFAULT_EXPIRATION=1800
```

## 📚 Additional Resources

- [AWS CloudFront Signed URLs Documentation](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/private-content-signed-urls.html)
- [Laravel Testing Documentation](https://laravel.com/docs/testing)
- [AWS SDK for PHP Documentation](https://docs.aws.amazon.com/sdk-for-php/)

## 🎉 Success Criteria

Your CloudFront signing service is working correctly when:

1. ✅ Configuration validation passes
2. ✅ Signed URLs are generated successfully
3. ✅ Signed URLs serve your content correctly
4. ✅ URLs expire at the specified time
5. ✅ IP whitelisting works as expected
6. ✅ All file types are handled correctly
7. ✅ Error conditions are handled gracefully
8. ✅ API endpoints respond correctly
9. ✅ Integration with your application works
10. ✅ Performance meets your requirements

---

**Remember:** The key to successful testing is to start simple (basic URL signing) and gradually test more complex scenarios (IP whitelisting, multiple URLs, error conditions).