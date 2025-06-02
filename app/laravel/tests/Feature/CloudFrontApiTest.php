<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CloudFrontApiTest extends TestCase
{
    private string $testPrivateKeyPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a test private key file
        $this->createTestPrivateKey();
        
        // Configure test settings
        Config::set('services.cloudfront.private_key_path', $this->testPrivateKeyPath);
        Config::set('services.cloudfront.key_pair_id', 'APKAJKYJ7CQO2ZKTVR4Q');
        Config::set('services.cloudfront.region', 'us-east-1');
        Config::set('services.cloudfront.default_expiration', 300);
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
    public function it_can_sign_single_url_via_api()
    {
        $response = $this->postJson('/api/cloudfront/sign-url', [
            'server' => 'https://d1234567890.cloudfront.net',
            'file' => '/path/to/test-file.mp4',
            'seconds' => 3600,
            'whitelist' => false
        ]);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'signed_url',
                    'expires_in'
                ])
                ->assertJson([
                    'success' => true,
                    'expires_in' => 3600
                ]);

        $signedUrl = $response->json('signed_url');
        $this->assertStringContainsString('https://d1234567890.cloudfront.net', $signedUrl);
        $this->assertStringContainsString('/path/to/test-file.mp4', $signedUrl);
        $this->assertStringContainsString('Expires=', $signedUrl);
        $this->assertStringContainsString('Signature=', $signedUrl);
    }

    /** @test */
    public function it_can_sign_multiple_urls_via_api()
    {
        $response = $this->postJson('/api/cloudfront/sign-multiple-urls', [
            'urls' => [
                'https://d1234567890.cloudfront.net/video1.mp4',
                'https://d1234567890.cloudfront.net/video2.mp4',
                'https://d1234567890.cloudfront.net/audio/track.mp3'
            ],
            'seconds' => 1800,
            'whitelist' => false
        ]);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'signed_urls',
                    'expires_in'
                ])
                ->assertJson([
                    'success' => true,
                    'expires_in' => 1800
                ]);

        $signedUrls = $response->json('signed_urls');
        $this->assertCount(3, $signedUrls);
        
        foreach ($signedUrls as $signedUrl) {
            $this->assertNotNull($signedUrl);
            $this->assertStringContainsString('Expires=', $signedUrl);
            $this->assertStringContainsString('Signature=', $signedUrl);
        }
    }

    /** @test */
    public function it_validates_configuration_via_api()
    {
        $response = $this->getJson('/api/cloudfront/validate-config');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'message'
                ])
                ->assertJson([
                    'success' => true,
                    'message' => 'CloudFront configuration is valid'
                ]);
    }

    /** @test */
    public function it_validates_required_fields_for_single_url()
    {
        $response = $this->postJson('/api/cloudfront/sign-url', [
            'file' => '/test.mp4'
            // Missing required 'server' field
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['server']);
    }

    /** @test */
    public function it_validates_required_fields_for_multiple_urls()
    {
        $response = $this->postJson('/api/cloudfront/sign-multiple-urls', [
            'seconds' => 3600
            // Missing required 'urls' field
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['urls']);
    }

    /** @test */
    public function it_validates_seconds_parameter_limits()
    {
        // Test minimum limit
        $response = $this->postJson('/api/cloudfront/sign-url', [
            'server' => 'https://d1234567890.cloudfront.net',
            'file' => '/test.mp4',
            'seconds' => 0 // Below minimum
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['seconds']);

        // Test maximum limit
        $response = $this->postJson('/api/cloudfront/sign-url', [
            'server' => 'https://d1234567890.cloudfront.net',
            'file' => '/test.mp4',
            'seconds' => 86401 // Above maximum (24 hours)
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['seconds']);
    }

    /** @test */
    public function it_uses_default_values_when_optional_parameters_not_provided()
    {
        $response = $this->postJson('/api/cloudfront/sign-url', [
            'server' => 'https://d1234567890.cloudfront.net'
            // No file, seconds, or whitelist parameters
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'expires_in' => 300 // Default expiration
                ]);

        $signedUrl = $response->json('signed_url');
        $this->assertStringContainsString('https://d1234567890.cloudfront.net', $signedUrl);
    }

    /** @test */
    public function it_handles_s3_file_urls_correctly()
    {
        // Test with a typical S3 file URL structure
        $s3FileUrl = 'https://d1234567890.cloudfront.net/uploads/videos/2024/01/15/sample-video.mp4';
        
        $response = $this->postJson('/api/cloudfront/sign-url', [
            'server' => 'https://d1234567890.cloudfront.net',
            'file' => '/uploads/videos/2024/01/15/sample-video.mp4',
            'seconds' => 3600
        ]);

        $response->assertStatus(200);
        
        $signedUrl = $response->json('signed_url');
        $this->assertStringContainsString('/uploads/videos/2024/01/15/sample-video.mp4', $signedUrl);
        $this->assertStringContainsString('Expires=', $signedUrl);
        $this->assertStringContainsString('Signature=', $signedUrl);
        $this->assertStringContainsString('Key-Pair-Id=', $signedUrl);
    }

    /** @test */
    public function it_handles_different_file_types()
    {
        $fileTypes = [
            '/video/sample.mp4',
            '/audio/track.mp3',
            '/documents/manual.pdf',
            '/tabs/song.ptb',
            '/tabs/guitar.gp5',
            '/images/cover.jpg'
        ];

        foreach ($fileTypes as $file) {
            $response = $this->postJson('/api/cloudfront/sign-url', [
                'server' => 'https://d1234567890.cloudfront.net',
                'file' => $file,
                'seconds' => 1800
            ]);

            $response->assertStatus(200);
            
            $signedUrl = $response->json('signed_url');
            $this->assertStringContainsString($file, $signedUrl);
            $this->assertStringContainsString('Expires=', $signedUrl);
        }
    }

    /** @test */
    public function it_returns_error_when_configuration_is_invalid()
    {
        // Remove the test private key to simulate invalid configuration
        unlink($this->testPrivateKeyPath);

        $response = $this->postJson('/api/cloudfront/sign-url', [
            'server' => 'https://d1234567890.cloudfront.net',
            'file' => '/test.mp4'
        ]);

        $response->assertStatus(500)
                ->assertJson([
                    'success' => false
                ])
                ->assertJsonStructure([
                    'success',
                    'error'
                ]);
    }
}