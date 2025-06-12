<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Segment extends Model
{
    use HasFactory;

    /**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = 'truefire';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'channels.segments';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        // Add your fillable attributes here based on the actual table structure
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        // Add your casts here based on the actual table structure
    ];

    /**
     * Get the channel that owns the segment.
     */
    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id', 'id');
    }

    /**
     * Get the course through the channel relationship.
     */
    public function course()
    {
        return $this->hasOneThrough(
            TruefireCourse::class,
            Channel::class,
            'id',        // Foreign key on channels table
            'id',        // Foreign key on courses table
            'channel_id', // Local key on segments table
            'courseid'   // Local key on channels table
        );
    }

    /**
     * Scope a query to only include segments with valid video fields.
     * A valid video field must be not null, not empty, start with 'mp4:', and have content after the prefix.
     */
    public function scopeWithVideo($query)
    {
        $tableName = $this->getTable();
        return $query->whereNotNull($tableName . '.video')
                    ->where($tableName . '.video', '!=', '')
                    ->where($tableName . '.video', 'LIKE', 'mp4:_%'); // Changed to require at least one character after mp4:
    }

    /**
     * Check if this segment has a valid video field.
     * A valid video field must be not null, not empty, start with 'mp4:', and have content after the prefix.
     *
     * @return bool
     */
    public function hasValidVideo()
    {
        return !empty($this->video) &&
               str_starts_with($this->video, 'mp4:') &&
               strlen($this->video) > 4; // Ensure there's content after 'mp4:'
    }

    public function getSignedUrl($expirationSeconds = 518400)
    {
        try {
            // Validate AWS credentials are available
            $this->validateAwsCredentials();
            
            // Construct the S3 key path from the video field
            $video = str_replace('mp4:', '', $this->video) . '_med.mp4';
            
            \Log::info('Generating S3 signed URL for segment', [
                'segment_id' => $this->id,
                'video_field' => $this->video,
                's3_key' => $video,
                'expiration_seconds' => $expirationSeconds
            ]);
            
            // Use Laravel's S3 disk configuration for tfstream bucket
            $tfstreamDisk = $this->getTfstreamS3Disk();
            
            // Generate temporary URL using Laravel's Storage facade
            $temporaryUrl = $tfstreamDisk->temporaryUrl(
                $video,
                now()->addSeconds($expirationSeconds)
            );
            
            \Log::info('Successfully generated S3 signed URL', [
                'segment_id' => $this->id,
                's3_key' => $video,
                'url_length' => strlen($temporaryUrl)
            ]);
            
            return $temporaryUrl;
            
        } catch (\Exception $e) {
            \Log::error('Failed to generate S3 signed URL for segment', [
                'segment_id' => $this->id,
                'video' => $this->video,
                's3_key' => isset($video) ? $video : 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new \Exception("Failed to generate S3 signed URL: " . $e->getMessage());
        }
    }
    
    /**
     * Validate that AWS credentials are properly configured
     */
    private function validateAwsCredentials(): void
    {
        $accessKey = config('filesystems.disks.s3.key');
        $secretKey = config('filesystems.disks.s3.secret');
        $profile = config('filesystems.disks.s3.profile');
        
        // Check if we have explicit credentials or AWS profile
        if (empty($accessKey) && empty($secretKey)) {
            // Check if AWS profile is configured for credential resolution
            if (empty($profile) || $profile === 'default') {
                // Check if AWS credential files exist in Docker environment
                $credentialsFile = env('AWS_SHARED_CREDENTIALS_FILE', '/mnt/aws_creds_mounted/credentials');
                $configFile = env('AWS_CONFIG_FILE', '/mnt/aws_creds_mounted/config');
                
                if (!file_exists($credentialsFile) && !file_exists($configFile)) {
                    throw new \Exception(
                        'AWS credentials not configured. Either set TF_AWS_ACCESS_KEY_ID/TF_SECRET_ACCESS_KEY ' .
                        'or AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY or ensure AWS credential files are mounted at /mnt/aws_creds_mounted/'
                    );
                }
            }
        }
        
        \Log::debug('AWS credentials validation passed', [
            'has_access_key' => !empty($accessKey),
            'has_secret_key' => !empty($secretKey),
            'profile' => $profile,
            'credentials_file' => env('AWS_SHARED_CREDENTIALS_FILE'),
            'config_file' => env('AWS_CONFIG_FILE')
        ]);
    }
    
    /**
     * Get S3 disk configured for tfstream bucket
     */
    private function getTfstreamS3Disk()
    {
        // Create a custom S3 disk configuration for tfstream bucket
        $s3Config = config('filesystems.disks.s3');
        $s3Config['bucket'] = 'tfstream'; // Override bucket for tfstream
        
        // Create disk instance with tfstream bucket configuration
        return \Storage::build($s3Config);
    }

    public function s3Path()
    {
        $path = str_replace('mp4:','', $this->video);
        $path = explode('/', $path)[0];
        $videoFile = @end(explode('/', $this->video)).'_med.mp4';
        $s3Path = "{$path}/{$videoFile}";
        return $s3Path;
    }
    
}