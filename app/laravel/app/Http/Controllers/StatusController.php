<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class StatusController extends Controller
{
    /**
     * Display system status and connectivity checks.
     */
    public function index()
    {
        $checks = [
            'app' => $this->checkApp(),
            'local_database' => $this->checkLocalDatabase(),
            'truefire_database' => $this->checkTrueFireDatabase(),
            'redis' => $this->checkRedis(),
            'sqs' => $this->checkSqs(),
            's3' => $this->checkS3(),
            'tfstream' => $this->checkTfStreamS3(),
            'efs' => $this->checkEfs(),
            'container_storage' => $this->checkStorage(),
        ];

        $allHealthy = collect($checks)->every(fn($check) => 
            $check['status'] === 'ok' || $check['status'] === 'skip'
        );

        return Inertia::render('Status', [
            'checks' => $checks,
            'allHealthy' => $allHealthy,
            'environment' => [
                'app_env' => config('app.env'),
                'app_debug' => config('app.debug'),
                'app_url' => config('app.url'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * JSON endpoint for health checks (useful for monitoring).
     */
    public function json()
    {
        $checks = [
            'app' => $this->checkApp(),
            'local_database' => $this->checkLocalDatabase(),
            'truefire_database' => $this->checkTrueFireDatabase(),
            'redis' => $this->checkRedis(),
            'sqs' => $this->checkSqs(),
            's3' => $this->checkS3(),
            'tfstream' => $this->checkTfStreamS3(),
            'efs' => $this->checkEfs(),
            'container_storage' => $this->checkStorage(),
        ];

        $allHealthy = collect($checks)->every(fn($check) => 
            $check['status'] === 'ok' || $check['status'] === 'skip'
        );

        return response()->json([
            'healthy' => $allHealthy,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $allHealthy ? 200 : 503);
    }

    protected function checkApp(): array
    {
        return [
            'name' => 'Application',
            'status' => 'ok',
            'message' => 'Application is running',
            'details' => [
                'env' => config('app.env'),
                'debug' => config('app.debug') ? 'enabled' : 'disabled',
                'url' => config('app.url'),
            ],
        ];
    }

    protected function checkLocalDatabase(): array
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        
        try {
            $start = microtime(true);
            
            // Query tables based on database driver
            if ($driver === 'sqlite') {
                $tableCount = DB::connection($connection)->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            } else {
                $tableCount = DB::connection($connection)->select("SHOW TABLES");
            }
            $latency = round((microtime(true) - $start) * 1000, 2);

            // Try to get job counts for this transcription service
            $jobCount = null;
            $pendingJobs = null;
            try {
                $jobCount = DB::connection($connection)->table('transcription_jobs')->count();
                $pendingJobs = DB::connection($connection)->table('transcription_jobs')
                    ->where('status', 'pending')
                    ->count();
            } catch (\Exception $e) {
                // Tables might not exist yet
            }

            return [
                'name' => 'Local Database',
                'status' => 'ok',
                'message' => "Connected - " . count($tableCount) . " tables",
                'details' => [
                    'driver' => $connection,
                    'host' => config("database.connections.{$connection}.host", 'N/A'),
                    'database' => config("database.connections.{$connection}.database"),
                    'tables' => count($tableCount),
                    'jobs' => $jobCount,
                    'pending' => $pendingJobs,
                    'latency_ms' => $latency,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Local database connection failed: ' . $e->getMessage());
            return [
                'name' => 'Local Database',
                'status' => 'error',
                'message' => 'Connection failed',
                'details' => [
                    'driver' => $connection,
                    'error' => substr($e->getMessage(), 0, 100),
                ],
            ];
        }
    }

    protected function checkTrueFireDatabase(): array
    {
        $host = config('database.connections.truefire.host');
        $database = config('database.connections.truefire.database');
        $username = config('database.connections.truefire.username');

        // Check if credentials are configured
        if (empty($username) || empty($host)) {
            return [
                'name' => 'TrueFire Database',
                'status' => 'skip',
                'message' => 'Not configured',
                'details' => [
                    'host' => $host ?? 'not set',
                    'username' => $username ?? 'not set',
                ],
            ];
        }

        try {
            $start = microtime(true);
            
            // Set a short timeout
            config(['database.connections.truefire.options' => [
                \PDO::ATTR_TIMEOUT => 5,
            ]]);
            
            DB::connection('truefire')->reconnect();
            DB::connection('truefire')->select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);

            // Get sample counts to verify connection (not testing models, just connectivity)
            $courseCount = null;
            $authorCount = null;
            
            try {
                $courseCount = DB::connection('truefire')->table('truefire.courses')->count();
                $authorCount = DB::connection('truefire')->table('truefire.authors')->count();
            } catch (\Exception $e) {
                Log::warning('TrueFire table count error: ' . $e->getMessage());
            }

            return [
                'name' => 'TrueFire Database',
                'status' => 'ok',
                'message' => 'Connected successfully',
                'details' => [
                    'host' => $host,
                    'database' => $database,
                    'courses' => $courseCount,
                    'authors' => $authorCount,
                    'latency_ms' => $latency,
                ],
            ];
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            $shortError = str_contains($errorMessage, 'Connection refused')
                ? 'Connection refused - check network/security groups'
                : (str_contains($errorMessage, 'Access denied')
                    ? 'Access denied - check credentials'
                    : (str_contains($errorMessage, 'timed out')
                        ? 'Connection timed out - check VPC peering/routing'
                        : substr($errorMessage, 0, 100)));

            return [
                'name' => 'TrueFire Database',
                'status' => 'error',
                'message' => $shortError,
                'details' => [
                    'host' => $host,
                    'database' => $database,
                    'username' => $username,
                    'error' => $shortError,
                ],
            ];
        }
    }

    protected function checkRedis(): array
    {
        $host = config('database.redis.default.host');
        
        // Check if Redis is configured
        if (!$host || $host === '127.0.0.1') {
            return [
                'name' => 'Redis Cache',
                'status' => 'skip',
                'message' => 'Not configured (using localhost)',
                'details' => [
                    'host' => $host ?? 'not set',
                ],
            ];
        }

        try {
            $start = microtime(true);
            
            // Test with actual cache operations
            $testKey = 'health_check_' . time();
            $testValue = 'ok_' . uniqid();
            
            Cache::store('redis')->put($testKey, $testValue, 60);
            $retrieved = Cache::store('redis')->get($testKey);
            Cache::store('redis')->forget($testKey);
            
            $latency = round((microtime(true) - $start) * 1000, 2);
            
            $verified = $retrieved === $testValue;

            return [
                'name' => 'Redis Cache',
                'status' => $verified ? 'ok' : 'error',
                'message' => $verified ? 'Read/write verified' : 'Read/write failed',
                'details' => [
                    'host' => $host,
                    'port' => config('database.redis.default.port'),
                    'write_test' => $verified ? 'passed' : 'failed',
                    'latency_ms' => $latency,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'Redis Cache',
                'status' => 'error',
                'message' => 'Connection failed',
                'details' => [
                    'host' => $host,
                    'error' => substr($e->getMessage(), 0, 100),
                ],
            ];
        }
    }

    protected function checkSqs(): array
    {
        $queueConnection = config('queue.default');
        $queueName = config('queue.connections.sqs.queue');
        $region = config('queue.connections.sqs.region');

        if ($queueConnection !== 'sqs' || empty($queueName)) {
            return [
                'name' => 'SQS Queue',
                'status' => 'skip',
                'message' => 'Not configured',
                'details' => [
                    'connection' => $queueConnection,
                    'queue' => $queueName ?? 'not set',
                ],
            ];
        }

        try {
            $start = microtime(true);
            
            // Get queue attributes to verify access
            $sqs = new \Aws\Sqs\SqsClient([
                'version' => 'latest',
                'region' => $region ?? 'us-east-1',
            ]);

            $result = $sqs->getQueueUrl([
                'QueueName' => $queueName,
            ]);
            
            $queueUrl = $result['QueueUrl'];
            
            // Get queue attributes
            $attrs = $sqs->getQueueAttributes([
                'QueueUrl' => $queueUrl,
                'AttributeNames' => ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible'],
            ]);
            
            $latency = round((microtime(true) - $start) * 1000, 2);
            
            $messagesVisible = $attrs['Attributes']['ApproximateNumberOfMessages'] ?? 0;
            $messagesInFlight = $attrs['Attributes']['ApproximateNumberOfMessagesNotVisible'] ?? 0;

            return [
                'name' => 'SQS Queue',
                'status' => 'ok',
                'message' => 'Connected - ' . ($messagesVisible + $messagesInFlight) . ' messages',
                'details' => [
                    'queue' => $queueName,
                    'region' => $region,
                    'messages_visible' => (int)$messagesVisible,
                    'messages_in_flight' => (int)$messagesInFlight,
                    'latency_ms' => $latency,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'SQS Queue',
                'status' => 'error',
                'message' => 'Access failed',
                'details' => [
                    'queue' => $queueName,
                    'error' => substr($e->getMessage(), 0, 100),
                ],
            ];
        }
    }

    protected function checkS3(): array
    {
        $bucket = config('filesystems.disks.s3.bucket');
        $region = config('filesystems.disks.s3.region');

        if (empty($bucket)) {
            // Try AWS_BUCKET env var
            $bucket = env('AWS_BUCKET');
        }

        if (empty($bucket)) {
            return [
                'name' => 'S3 Storage',
                'status' => 'skip',
                'message' => 'Not configured',
                'details' => [
                    'bucket' => 'not set',
                ],
            ];
        }

        try {
            $start = microtime(true);
            
            $s3 = new S3Client([
                'version' => 'latest',
                'region' => $region ?? 'us-east-1',
            ]);

            // Test by listing objects (limit 1)
            $result = $s3->listObjectsV2([
                'Bucket' => $bucket,
                'MaxKeys' => 5,
            ]);
            
            $latency = round((microtime(true) - $start) * 1000, 2);
            
            $objectCount = $result['KeyCount'] ?? 0;

            // Try to write and read a test file
            $testKey = 'health-check/test-' . time() . '.txt';
            $testContent = 'Health check at ' . now()->toIso8601String();
            $writeTest = false;
            $readTest = false;
            
            try {
                $s3->putObject([
                    'Bucket' => $bucket,
                    'Key' => $testKey,
                    'Body' => $testContent,
                ]);
                $writeTest = true;
                
                $getResult = $s3->getObject([
                    'Bucket' => $bucket,
                    'Key' => $testKey,
                ]);
                $readTest = $getResult['Body']->getContents() === $testContent;
                
                // Cleanup
                $s3->deleteObject([
                    'Bucket' => $bucket,
                    'Key' => $testKey,
                ]);
            } catch (\Exception $e) {
                Log::warning('S3 write/read test failed: ' . $e->getMessage());
            }

            return [
                'name' => 'S3 Storage',
                'status' => 'ok',
                'message' => 'Connected - ' . $objectCount . ' objects listed',
                'details' => [
                    'bucket' => $bucket,
                    'region' => $region,
                    'objects_sample' => $objectCount,
                    'write_test' => $writeTest ? 'passed' : 'failed',
                    'read_test' => $readTest ? 'passed' : 'failed',
                    'latency_ms' => $latency,
                ],
            ];
        } catch (AwsException $e) {
            return [
                'name' => 'S3 Storage',
                'status' => 'error',
                'message' => 'Access failed',
                'details' => [
                    'bucket' => $bucket,
                    'error' => $e->getAwsErrorMessage() ?? substr($e->getMessage(), 0, 100),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'S3 Storage',
                'status' => 'error',
                'message' => 'Access failed',
                'details' => [
                    'bucket' => $bucket,
                    'error' => substr($e->getMessage(), 0, 100),
                ],
            ];
        }
    }

    /**
     * Check TrueFire tfstream S3 bucket (cross-account access).
     */
    protected function checkTfStreamS3(): array
    {
        $bucket = 'tfstream';
        $region = 'us-east-1';

        try {
            $start = microtime(true);
            
            $config = [
                'version' => 'latest',
                'region' => $region,
            ];
            
            // Use profile locally, ECS task role in production
            $profile = env('TRUEFIRE_AWS_PROFILE');
            if ($profile) {
                $config['profile'] = $profile;
            }
            
            $s3 = new S3Client($config);

            // List a few objects to verify access
            $result = $s3->listObjectsV2([
                'Bucket' => $bucket,
                'MaxKeys' => 5,
                'Delimiter' => '/',
            ]);
            
            $latency = round((microtime(true) - $start) * 1000, 2);
            
            $folderCount = count($result['CommonPrefixes'] ?? []);
            $sampleFolders = array_slice(
                array_map(fn($p) => rtrim($p['Prefix'], '/'), $result['CommonPrefixes'] ?? []),
                0, 3
            );

            return [
                'name' => 'TrueFire TFStream',
                'status' => 'ok',
                'message' => 'Cross-account access working',
                'details' => [
                    'bucket' => $bucket,
                    'account' => '522470447970 (TrueFire)',
                    'folders_sample' => $folderCount,
                    'sample' => implode(', ', $sampleFolders),
                    'latency_ms' => $latency,
                ],
            ];
        } catch (AwsException $e) {
            return [
                'name' => 'TrueFire TFStream',
                'status' => 'error',
                'message' => 'Cross-account access denied',
                'details' => [
                    'bucket' => $bucket,
                    'error' => $e->getAwsErrorMessage() ?? substr($e->getMessage(), 0, 100),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'TrueFire TFStream',
                'status' => 'error',
                'message' => 'Access failed',
                'details' => [
                    'bucket' => $bucket,
                    'error' => substr($e->getMessage(), 0, 100),
                ],
            ];
        }
    }

    protected function checkStorage(): array
    {
        try {
            $disk = config('filesystems.default');
            $start = microtime(true);
            
            // Test write
            $testFile = 'health-check-' . time() . '.txt';
            $testContent = 'Health check at ' . now()->toIso8601String();
            
            Storage::put($testFile, $testContent);
            $writeSuccess = Storage::exists($testFile);
            
            // Test read
            $readContent = Storage::get($testFile);
            $readSuccess = $readContent === $testContent;
            
            // Cleanup
            Storage::delete($testFile);
            
            $latency = round((microtime(true) - $start) * 1000, 2);
            
            // Get storage stats
            $path = Storage::path('');
            $freeSpace = null;
            $totalSpace = null;
            if (is_dir($path)) {
                $freeSpace = disk_free_space($path);
                $totalSpace = disk_total_space($path);
            }

            return [
                'name' => 'Container Storage',
                'status' => ($writeSuccess && $readSuccess) ? 'ok' : 'error',
                'message' => ($writeSuccess && $readSuccess) ? 'Read/write verified' : 'Read/write failed',
                'details' => [
                    'disk' => $disk,
                    'path' => $path,
                    'write_test' => $writeSuccess ? 'passed' : 'failed',
                    'read_test' => $readSuccess ? 'passed' : 'failed',
                    'free_space' => $freeSpace ? round($freeSpace / 1024 / 1024 / 1024, 2) . ' GB' : 'N/A',
                    'total_space' => $totalSpace ? round($totalSpace / 1024 / 1024 / 1024, 2) . ' GB' : 'N/A',
                    'latency_ms' => $latency,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'Container Storage',
                'status' => 'error',
                'message' => 'Storage check failed',
                'details' => [
                    'error' => substr($e->getMessage(), 0, 100),
                ],
            ];
        }
    }

    protected function checkEfs(): array
    {
        // EFS mount path - typically /mnt/efs or configured path
        $efsPath = env('EFS_MOUNT_PATH', '/mnt/efs');
        
        // Quick check using /proc/mounts to avoid blocking on unresponsive mounts
        $isMounted = false;
        $mountInfo = 'unknown';
        
        try {
            if (file_exists('/proc/mounts')) {
                $mounts = @file_get_contents('/proc/mounts');
                if ($mounts) {
                    // Check if EFS is in the mount list
                    $isMounted = strpos($mounts, 'nfs4') !== false && strpos($mounts, $efsPath) !== false;
                    // Get mount details for debugging
                    foreach (explode("\n", $mounts) as $line) {
                        if (strpos($line, $efsPath) !== false) {
                            $mountInfo = substr($line, 0, 100);
                            break;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $mountInfo = 'error: ' . $e->getMessage();
        }
        
        if (!$isMounted) {
            return [
                'name' => 'EFS Storage',
                'status' => 'skip',
                'message' => 'Not mounted',
                'details' => [
                    'path' => $efsPath,
                    'mounted' => false,
                    'mount_info' => $mountInfo,
                ],
            ];
        }

        // EFS is mounted - try a quick access test with process-level timeout
        try {
            $start = microtime(true);
            
            // Use a subprocess with timeout to avoid hanging
            $testFile = $efsPath . '/health-check-' . getmypid() . '-' . time() . '.txt';
            $cmd = sprintf(
                'timeout 3 bash -c "echo test > %s && cat %s && rm -f %s" 2>&1',
                escapeshellarg($testFile),
                escapeshellarg($testFile),
                escapeshellarg($testFile)
            );
            
            $output = null;
            $returnCode = null;
            exec($cmd, $output, $returnCode);
            
            $latency = round((microtime(true) - $start) * 1000, 2);
            $success = $returnCode === 0;

            return [
                'name' => 'EFS Storage',
                'status' => $success ? 'ok' : 'error',
                'message' => $success ? 'Read/write verified' : 'Access test failed',
                'details' => [
                    'path' => $efsPath,
                    'mounted' => true,
                    'access_test' => $success ? 'passed' : 'failed (code: ' . $returnCode . ')',
                    'latency_ms' => $latency,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'name' => 'EFS Storage',
                'status' => 'error',
                'message' => 'EFS check failed',
                'details' => [
                    'path' => $efsPath,
                    'error' => substr($e->getMessage(), 0, 100),
                ],
            ];
        }
    }
}
