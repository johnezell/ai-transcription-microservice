<?php

namespace Tests\Feature;

use App\Services\CloudFrontSigningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CloudFrontSigningTest extends TestCase
{
    private CloudFrontSigningService $cloudFrontService;
    private string $testPrivateKeyPath;
    private string $testKeyPairId = 'APKAJKYJ7CQO2ZKTVR4Q';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a test private key file
        $this->createTestPrivateKey();
        
        // Configure test settings
        Config::set('services.cloudfront.private_key_path', $this->testPrivateKeyPath);
        Config::set('services.cloudfront.key_pair_id', $this->testKeyPairId);
        Config::set('services.cloudfront.region', 'us-east-1');
        Config::set('services.cloudfront.default_expiration', 300);
        
        $this->cloudFrontService = new CloudFrontSigningService();
    }

    protected function tearDown(): void
    {
        // Clean up test private key
        if (file_exists($this->testPrivateKeyPath)) {
            unlink($this->testPrivateKeyPath);
        }
        
        parent::tearDown();
    }

    private function createTestPrivateKey(): void
    {
        // Create a test RSA private key for testing
        $testPrivateKey = "-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEA4f6wg4PiT9Hfx8lnGhwHNVmXdBXTxtMydryHxmHi3iVSiSkHcBpXF5EApeMmL0OtUb
gZQckA9cHtTeiwiVarsNHtnQRaJeHqg5Q4RYdIllgQOHSco2cQoPXdOfy6v4CCHCOxF755mF0L+K+Yt6
OGT0ePro4fcg5ic7SFvxwyxrxQsQEdFCSqGSIb4CS5DpAyycE6S8H6tDEFHjbMQvHyspUBDDvwqbRdyL
rHBRKt7oREfP+cDWw5u5LgNcHdyTxJhyQdgXkE0v6yQ1dA5v4dhSIUKhWRnPpNVwQQ+U+DB1uE5BR4
wIBAg==
-----END RSA PRIVATE KEY-----";

        $this->testPrivateKeyPath = storage_path('app/test_cloudfront_key.pem');
        file_put_contents($this->testPrivateKeyPath, $testPrivateKey);
    }

    /** @test */
    public function it_can_validate_configuration()
    {
        $isValid = $this->cloudFrontService->validateConfiguration();
        $this->assertTrue($isValid);
    }

    /** @test */
    public function it_can_sign_a_single_url()
    {
        $server = 'https://d1234567890.cloudfront.net';
        $file = '/path/to/test-file.mp4';
        $seconds = 3600;

        $signedUrl = $this->cloudFrontService->signUrl($server, $file, $seconds, false);

        $this->assertStringContainsString($server, $signedUrl);
        $this->assertStringContainsString($file, $signedUrl);
        $this->assertStringContainsString('Expires=', $signedUrl);
        $this->assertStringContainsString('Signature=', $signedUrl);
        $this->assertStringContainsString('Key-Pair-Id=', $signedUrl);
    }

    /** @test */
    public function it_can_sign_multiple_urls()
    {
        $urls = [
            'video1' => 'https://d1234567890.cloudfront.net/video1.mp4',
            'video2' => 'https://d1234567890.cloudfront.net/video2.mp4',
            'audio1' => [
                'server' => 'https://d1234567890.cloudfront.net',
                'file' => '/audio/track1.mp3'
            ]
        ];

        $signedUrls = $this->cloudFrontService->signMultipleUrls($urls, 3600, false);

        $this->assertCount(3, $signedUrls);
        $this->assertArrayHasKey('video1', $signedUrls);
        $this->assertArrayHasKey('video2', $signedUrls);
        $this->assertArrayHasKey('audio1', $signedUrls);

        foreach ($signedUrls as $signedUrl) {
            $this->assertNotNull($signedUrl);
            $this->assertStringContainsString('Expires=', $signedUrl);
            $this->assertStringContainsString('Signature=', $signedUrl);
        }
    }

    /** @test */
    public function it_handles_ip_whitelisting_for_non_restricted_files()
    {
        $server = 'https://d1234567890.cloudfront.net';
        $file = '/video/test.mp4'; // Non-restricted file type

        // Mock the REMOTE_ADDR for testing
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';

        $signedUrl = $this->cloudFrontService->signUrl($server, $file, 3600, true);

        $this->assertStringContainsString('Policy=', $signedUrl);
        $this->assertStringContainsString('Signature=', $signedUrl);
        
        // Clean up
        unset($_SERVER['REMOTE_ADDR']);
    }

    /** @test */
    public function it_skips_ip_whitelisting_for_restricted_file_types()
    {
        $server = 'https://d1234567890.cloudfront.net';
        $restrictedFiles = [
            '/documents/manual.pdf',
            '/audio/track.mp3',
            '/tabs/song.ptb',
            '/tabs/song.gp5'
        ];

        foreach ($restrictedFiles as $file) {
            $signedUrl = $this->cloudFrontService->signUrl($server, $file, 3600, true);
            
            // Should use simple signing (no Policy parameter) for restricted files
            $this->assertStringNotContainsString('Policy=', $signedUrl);
            $this->assertStringContainsString('Expires=', $signedUrl);
        }
    }

    /** @test */
    public function it_uses_default_expiration_when_not_specified()
    {
        $server = 'https://d1234567890.cloudfront.net';
        $file = '/test.mp4';

        $signedUrl = $this->cloudFrontService->signUrl($server, $file);

        $this->assertStringContainsString('Expires=', $signedUrl);
        
        // Extract expiration timestamp and verify it's approximately 300 seconds from now
        preg_match('/Expires=(\d+)/', $signedUrl, $matches);
        $expirationTime = (int) $matches[1];
        $expectedExpiration = time() + 300;
        
        $this->assertEqualsWithDelta($expectedExpiration, $expirationTime, 5);
    }

    /** @test */
    public function it_throws_exception_for_missing_private_key()
    {
        // Remove the test private key
        unlink($this->testPrivateKeyPath);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('CloudFront private key file not found');

        $this->cloudFrontService->signUrl('https://example.com', '/test.mp4');
    }
}