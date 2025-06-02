# CloudFront URL Signing Service

This Laravel application includes a CloudFront URL signing service that uses the official AWS SDK for PHP to generate signed URLs for CloudFront distributions.

## Setup

### 1. Environment Configuration

Add the following environment variables to your `.env` file:

```env
# CloudFront Configuration
CLOUDFRONT_PRIVATE_KEY_PATH=/path/to/your/private-key.pem
CLOUDFRONT_KEY_PAIR_ID=APKAJKYJ7CQO2ZKTVR4Q
CLOUDFRONT_REGION=us-east-1
CLOUDFRONT_DEFAULT_EXPIRATION=300
```

### 2. Private Key Setup

Place your CloudFront private key file in the `storage/app/cloudfront/` directory:

```bash
mkdir -p storage/app/cloudfront
cp /path/to/your/pk-APKAJKYJ7CQO2ZKTVR4Q.pem storage/app/cloudfront/
```

## Usage

### Using the Service Directly

```php
use App\Services\CloudFrontSigningService;

// Inject the service
public function __construct(CloudFrontSigningService $cloudFrontService)
{
    $this->cloudFrontService = $cloudFrontService;
}

// Sign a single URL
$signedUrl = $this->cloudFrontService->signUrl(
    'https://d1234567890.cloudfront.net',  // CloudFront domain
    '/path/to/file.mp4',                   // File path
    3600,                                  // Expiration in seconds (1 hour)
    false                                  // IP whitelist (false = no IP restriction)
);

// Sign multiple URLs
$urls = [
    'video1' => 'https://d1234567890.cloudfront.net/video1.mp4',
    'video2' => 'https://d1234567890.cloudfront.net/video2.mp4'
];

$signedUrls = $this->cloudFrontService->signMultipleUrls($urls, 3600, false);
```

### Using the API Endpoints

#### Sign a Single URL

```bash
POST /api/cloudfront/sign-url
Content-Type: application/json

{
    "server": "https://d1234567890.cloudfront.net",
    "file": "/path/to/file.mp4",
    "seconds": 3600,
    "whitelist": false
}
```

Response:
```json
{
    "success": true,
    "signed_url": "https://d1234567890.cloudfront.net/path/to/file.mp4?Policy=...",
    "expires_in": 3600
}
```

#### Sign Multiple URLs

```bash
POST /api/cloudfront/sign-multiple-urls
Content-Type: application/json

{
    "urls": [
        "https://d1234567890.cloudfront.net/video1.mp4",
        "https://d1234567890.cloudfront.net/video2.mp4"
    ],
    "seconds": 3600,
    "whitelist": false
}
```

#### Validate Configuration

```bash
GET /api/cloudfront/validate-config
```

Response:
```json
{
    "success": true,
    "message": "CloudFront configuration is valid"
}
```

## Features

### IP Whitelisting

The service supports IP-based access restrictions. When `whitelist` is set to `true`, the signed URL will only work from the IP address that made the signing request.

**Note**: IP whitelisting is automatically disabled for certain file types (PDF, MP3, PTB, GP5) to ensure compatibility.

### File Type Handling

The service automatically adjusts signing policies based on file extensions:

- **Restricted files** (pdf, mp3, ptb, gp5): No IP restrictions applied
- **Other files**: IP restrictions applied when `whitelist=true`

### Error Handling

The service includes comprehensive error handling and logging:

- Invalid private key paths
- Missing configuration
- AWS SDK errors
- File access issues

All errors are logged to Laravel's logging system for debugging.

### Batch Processing

You can sign multiple URLs in a single request, which is more efficient than making individual requests for each URL.

## Security Considerations

1. **Private Key Security**: Keep your CloudFront private key file secure and never commit it to version control.

2. **Expiration Times**: Use appropriate expiration times. Shorter times are more secure but may cause issues with long-running downloads.

3. **IP Whitelisting**: Use IP whitelisting for sensitive content, but be aware it may cause issues for users behind NAT or with changing IP addresses.

4. **HTTPS**: Always use HTTPS for both your application and CloudFront distribution.

## Troubleshooting

### Common Issues

1. **"Private key file not found"**: Ensure the private key file exists at the specified path and has proper permissions.

2. **"Invalid signature"**: Check that your key pair ID matches the private key file.

3. **"Access denied"**: Verify that your CloudFront distribution is properly configured with the trusted key group.

### Validation

Use the validation endpoint to check your configuration:

```bash
curl -X GET http://your-app.com/api/cloudfront/validate-config
```

This will verify that your private key file exists and can be read.