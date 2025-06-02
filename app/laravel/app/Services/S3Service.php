<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Exception;
use Illuminate\Support\Facades\Log;

class S3Service
{
    protected $disk;
    protected $localDisk;

    public function __construct()
    {
        $this->disk = Storage::disk('s3');
        $this->localDisk = Storage::disk('local');
    }

    /**
     * Upload a file to S3
     *
     * @param UploadedFile|string $file
     * @param string $directory
     * @param string|null $filename
     * @param bool $isPublic
     * @return array|false
     */
    public function uploadFile($file, string $directory = '', ?string $filename = null, bool $isPublic = false)
    {
        try {
            // Generate filename if not provided
            if (!$filename) {
                if ($file instanceof UploadedFile) {
                    $extension = $file->getClientOriginalExtension();
                    $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                    $filename = Str::slug($originalName) . '_' . time() . '.' . $extension;
                } else {
                    $filename = time() . '_' . Str::random(10);
                }
            }

            // Construct the full path
            $path = $directory ? $directory . '/' . $filename : $filename;

            // Upload file
            if ($file instanceof UploadedFile) {
                $storedPath = $this->disk->putFileAs($directory, $file, $filename, $isPublic ? 'public' : 'private');
            } else {
                $storedPath = $this->disk->put($path, $file, $isPublic ? 'public' : 'private');
            }

            if (!$storedPath) {
                throw new Exception('Failed to upload file to S3');
            }

            return [
                'success' => true,
                'path' => $storedPath,
                'url' => $this->getFileUrl($storedPath, $isPublic),
                'size' => $file instanceof UploadedFile ? $file->getSize() : strlen($file),
                'filename' => $filename,
                'directory' => $directory,
            ];

        } catch (Exception $e) {
            Log::error('S3 Upload Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Backup a local file to S3
     *
     * @param string $localPath
     * @param string $s3Directory
     * @param bool $isPublic
     * @return array|false
     */
    public function backupLocalFile(string $localPath, string $s3Directory = 'backups', bool $isPublic = false)
    {
        try {
            if (!$this->localDisk->exists($localPath)) {
                throw new Exception('Local file does not exist: ' . $localPath);
            }

            $content = $this->localDisk->get($localPath);
            $filename = basename($localPath);
            $s3Path = $s3Directory ? $s3Directory . '/' . $filename : $filename;

            $success = $this->disk->put($s3Path, $content, $isPublic ? 'public' : 'private');

            if (!$success) {
                throw new Exception('Failed to backup file to S3');
            }

            return [
                'success' => true,
                'local_path' => $localPath,
                's3_path' => $s3Path,
                'url' => $this->getFileUrl($s3Path, $isPublic),
                'size' => strlen($content),
            ];

        } catch (Exception $e) {
            Log::error('S3 Backup Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Download a file from S3 to local storage
     *
     * @param string $s3Path
     * @param string $localDirectory
     * @return array|false
     */
    public function downloadToLocal(string $s3Path, string $localDirectory = 'downloads')
    {
        try {
            if (!$this->disk->exists($s3Path)) {
                throw new Exception('S3 file does not exist: ' . $s3Path);
            }

            $content = $this->disk->get($s3Path);
            $filename = basename($s3Path);
            $localPath = $localDirectory ? $localDirectory . '/' . $filename : $filename;

            $success = $this->localDisk->put($localPath, $content);

            if (!$success) {
                throw new Exception('Failed to save file locally');
            }

            return [
                'success' => true,
                's3_path' => $s3Path,
                'local_path' => $localPath,
                'size' => strlen($content),
            ];

        } catch (Exception $e) {
            Log::error('S3 Download to Local Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Download a file from S3
     *
     * @param string $path
     * @return string|false
     */
    public function downloadFile(string $path)
    {
        try {
            if (!$this->disk->exists($path)) {
                throw new Exception('File does not exist: ' . $path);
            }

            return $this->disk->get($path);

        } catch (Exception $e) {
            Log::error('S3 Download Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a file from S3
     *
     * @param string $path
     * @return bool
     */
    public function deleteFile(string $path): bool
    {
        try {
            if (!$this->disk->exists($path)) {
                Log::warning('Attempted to delete non-existent file: ' . $path);
                return false;
            }

            return $this->disk->delete($path);

        } catch (Exception $e) {
            Log::error('S3 Delete Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a file exists in S3
     *
     * @param string $path
     * @return bool
     */
    public function fileExists(string $path): bool
    {
        try {
            return $this->disk->exists($path);
        } catch (Exception $e) {
            Log::error('S3 File Exists Check Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get file URL
     *
     * @param string $path
     * @param bool $isPublic
     * @return string
     */
    public function getFileUrl(string $path, bool $isPublic = false): string
    {
        try {
            if ($isPublic) {
                return $this->disk->url($path);
            } else {
                // Generate a temporary URL valid for 1 hour
                return $this->disk->temporaryUrl($path, now()->addHour());
            }
        } catch (Exception $e) {
            Log::error('S3 Get URL Error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Get file metadata
     *
     * @param string $path
     * @return array|false
     */
    public function getFileMetadata(string $path)
    {
        try {
            if (!$this->disk->exists($path)) {
                return false;
            }

            return [
                'size' => $this->disk->size($path),
                'last_modified' => $this->disk->lastModified($path),
                'mime_type' => $this->disk->mimeType($path),
                'url' => $this->getFileUrl($path),
            ];

        } catch (Exception $e) {
            Log::error('S3 Get Metadata Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * List files in a directory
     *
     * @param string $directory
     * @return array
     */
    public function listFiles(string $directory = ''): array
    {
        try {
            return $this->disk->files($directory);
        } catch (Exception $e) {
            Log::error('S3 List Files Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * List directories
     *
     * @param string $directory
     * @return array
     */
    public function listDirectories(string $directory = ''): array
    {
        try {
            return $this->disk->directories($directory);
        } catch (Exception $e) {
            Log::error('S3 List Directories Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Copy a file within S3
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    public function copyFile(string $from, string $to): bool
    {
        try {
            if (!$this->disk->exists($from)) {
                throw new Exception('Source file does not exist: ' . $from);
            }

            return $this->disk->copy($from, $to);

        } catch (Exception $e) {
            Log::error('S3 Copy Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Move/rename a file within S3
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    public function moveFile(string $from, string $to): bool
    {
        try {
            if (!$this->disk->exists($from)) {
                throw new Exception('Source file does not exist: ' . $from);
            }

            return $this->disk->move($from, $to);

        } catch (Exception $e) {
            Log::error('S3 Move Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a directory
     *
     * @param string $directory
     * @return bool
     */
    public function createDirectory(string $directory): bool
    {
        try {
            return $this->disk->makeDirectory($directory);
        } catch (Exception $e) {
            Log::error('S3 Create Directory Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a directory
     *
     * @param string $directory
     * @return bool
     */
    public function deleteDirectory(string $directory): bool
    {
        try {
            return $this->disk->deleteDirectory($directory);
        } catch (Exception $e) {
            Log::error('S3 Delete Directory Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get direct S3 disk access for advanced operations
     *
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    public function getDisk()
    {
        return $this->disk;
    }

    /**
     * Get local disk access
     *
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    public function getLocalDisk()
    {
        return $this->localDisk;
    }
} 