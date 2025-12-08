<?php

namespace App\Models\TrueFire;

use Illuminate\Database\Eloquent\Model;

/**
 * TrueFire Legacy Segment Model
 * 
 * Segments contain the actual video file references.
 * 
 * Database: channels.segments
 * 
 * Video path format:
 *   Raw:  "mp4:guitar-solo-factory-texas-blues-videos/ccsftb-07"
 *   S3:   "guitar-solo-factory-texas-blues-videos/ccsftb-07_hi.mp4"
 * 
 * S3 Bucket: truefire2
 * CloudFront: https://d3ldx91n93axbt.cloudfront.net
 */
class TrueFireSegment extends Model
{
    protected $connection = 'truefire';
    protected $table = 'channels.segments'; // Note: schema.table format
    protected $guarded = [];
    
    public $timestamps = false;
    
    // S3 and CloudFront configuration
    // Videos are in tfstream bucket, served via CloudFront
    const S3_BUCKET = 'tfstream';
    const CLOUDFRONT_BASE = 'https://d3ldx91n93axbt.cloudfront.net';
    
    // Video quality suffixes
    const QUALITY_LOW = '_low';
    const QUALITY_MED = '_med';
    const QUALITY_HI = '_hi';
    
    /**
     * Get the channel this segment belongs to.
     */
    public function channel()
    {
        return $this->belongsTo(TrueFireChannel::class, 'channel_id', 'id');
    }
    
    /**
     * Get the course this segment belongs to (through channel).
     */
    public function course()
    {
        return $this->hasOneThrough(
            TrueFireCourse::class,
            TrueFireChannel::class,
            'id',           // Foreign key on channels table
            'id',           // Foreign key on courses table  
            'channel_id',   // Local key on segments table
            'courseid'      // Local key on channels table
        );
    }
    
    /**
     * Parse the raw video path from the database.
     * 
     * Input:  "mp4:guitar-solo-factory-texas-blues-videos/ccsftb-07"
     * Output: "guitar-solo-factory-texas-blues-videos/ccsftb-07"
     */
    public function getVideoBasePath(): ?string
    {
        if (empty($this->video)) {
            return null;
        }
        
        // Remove the "mp4:" prefix
        return preg_replace('/^mp4:/', '', $this->video);
    }
    
    /**
     * Get the S3 folder path (first part before the slash).
     * 
     * Input:  "guitar-solo-factory-texas-blues-videos/ccsftb-07"
     * Output: "guitar-solo-factory-texas-blues-videos"
     */
    public function getS3FolderPath(): ?string
    {
        $basePath = $this->getVideoBasePath();
        if (!$basePath) {
            return null;
        }
        
        $parts = explode('/', $basePath);
        return $parts[0] ?? null;
    }
    
    /**
     * Get the video filename (without extension or quality suffix).
     * 
     * Input:  "guitar-solo-factory-texas-blues-videos/ccsftb-07"
     * Output: "ccsftb-07"
     */
    public function getVideoFilename(): ?string
    {
        $basePath = $this->getVideoBasePath();
        if (!$basePath) {
            return null;
        }
        
        $parts = explode('/', $basePath);
        return $parts[1] ?? null;
    }
    
    /**
     * Get the full S3 key for a specific quality.
     * 
     * @param string $quality One of: '_low', '_med', '_hi' (default: '_hi')
     * @return string|null Full S3 key like "guitar-solo-factory-texas-blues-videos/ccsftb-07_hi.mp4"
     */
    public function getS3Key(string $quality = self::QUALITY_HI): ?string
    {
        $basePath = $this->getVideoBasePath();
        if (!$basePath) {
            return null;
        }
        
        return $basePath . $quality . '.mp4';
    }
    
    /**
     * Get the full S3 URI for a specific quality.
     * 
     * @param string $quality One of: '_low', '_med', '_hi'
     * @return string|null Full S3 URI like "s3://truefire2/guitar-solo-factory-texas-blues-videos/ccsftb-07_hi.mp4"
     */
    public function getS3Uri(string $quality = self::QUALITY_HI): ?string
    {
        $key = $this->getS3Key($quality);
        if (!$key) {
            return null;
        }
        
        return 's3://' . self::S3_BUCKET . '/' . $key;
    }
    
    /**
     * Get the CloudFront URL for a specific quality.
     * 
     * @param string $quality One of: '_low', '_med', '_hi'
     * @return string|null Full CloudFront URL
     */
    public function getCloudFrontUrl(string $quality = self::QUALITY_HI): ?string
    {
        $key = $this->getS3Key($quality);
        if (!$key) {
            return null;
        }
        
        return self::CLOUDFRONT_BASE . '/' . $key;
    }
    
    /**
     * Get all available video URLs (all qualities).
     * 
     * @return array Associative array with 'low', 'med', 'hi' keys
     */
    public function getAllVideoUrls(): array
    {
        return [
            'low' => $this->getCloudFrontUrl(self::QUALITY_LOW),
            'med' => $this->getCloudFrontUrl(self::QUALITY_MED),
            'hi'  => $this->getCloudFrontUrl(self::QUALITY_HI),
        ];
    }
    
    /**
     * Get all S3 keys (all qualities).
     * 
     * @return array Associative array with 'low', 'med', 'hi' keys
     */
    public function getAllS3Keys(): array
    {
        return [
            'low' => $this->getS3Key(self::QUALITY_LOW),
            'med' => $this->getS3Key(self::QUALITY_MED),
            'hi'  => $this->getS3Key(self::QUALITY_HI),
        ];
    }
}

