<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\TruefireCourse;
use App\Models\LocalTruefireCourse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LocalTruefireCourseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder copies all TrueFire courses from the external database
     * to our local database for better performance and relationship management.
     * 
     * Note: This seeder only handles courses. Use LocalTruefireChannelSeeder
     * and LocalTruefireSegmentSeeder for channels and segments respectively.
     */
    public function run(): void
    {
        $this->command->info('Starting TrueFire courses synchronization...');
        
        try {
            // Get total counts for progress tracking
            $totalCourses = DB::connection('truefire')->table('courses')->count();
            
            $this->command->info("Found {$totalCourses} courses to sync");
            
            // Clear existing local courses data
            $this->command->info('Clearing existing local courses data...');
            LocalTruefireCourse::truncate();
            
            // Process courses in chunks to avoid memory issues
            $chunkSize = 100;
            $processedCount = 0;
            $errorCount = 0;
            
            DB::connection('truefire')->table('courses')
                ->orderBy('id')
                ->chunk($chunkSize, function ($courses) use (&$processedCount, &$errorCount) {
                    $localCourses = [];
                    
                    foreach ($courses as $course) {
                        try {
                            // Convert stdClass to array and prepare for local insertion
                            $courseData = (array) $course;
                            
                            // Add timestamps
                            $courseData['created_at'] = now();
                            $courseData['updated_at'] = now();
                            
                            // Handle any data type conversions if needed
                            $courseData = $this->sanitizeCourseData($courseData);
                            
                            $localCourses[] = $courseData;
                            $processedCount++;
                            
                        } catch (\Exception $e) {
                            $errorCount++;
                            Log::error('Error processing course during sync', [
                                'course_id' => $course->id ?? 'unknown',
                                'error' => $e->getMessage()
                            ]);
                            $this->command->error("Error processing course ID {$course->id}: " . $e->getMessage());
                        }
                    }
                    
                    // Bulk insert the chunk
                    if (!empty($localCourses)) {
                        try {
                            LocalTruefireCourse::insert($localCourses);
                            $this->command->info("Processed {$processedCount} courses...");
                        } catch (\Exception $e) {
                            $errorCount += count($localCourses);
                            Log::error('Error during bulk insert', [
                                'chunk_size' => count($localCourses),
                                'error' => $e->getMessage()
                            ]);
                            $this->command->error("Error during bulk insert: " . $e->getMessage());
                        }
                    }
                });
            
            $this->command->info("Courses synchronization completed!");
            $this->command->info("Successfully processed: {$processedCount} courses");
            
            if ($errorCount > 0) {
                $this->command->warn("Errors encountered: {$errorCount} courses");
            }
            
            // Verify the courses sync
            $localCoursesCount = LocalTruefireCourse::count();
            $this->command->info("Local courses table now contains: {$localCoursesCount} records");
            
            $this->command->info("=== Courses Synchronization Complete ===");
            $this->command->info("To sync channels, run: php artisan db:seed --class=LocalTruefireChannelSeeder");
            $this->command->info("To sync segments, run: php artisan db:seed --class=LocalTruefireSegmentSeeder (when available)");
            
        } catch (\Exception $e) {
            Log::error('Critical error during TrueFire courses synchronization', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->command->error('Critical error during synchronization: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Sanitize course data for local database insertion.
     *
     * @param array $courseData
     * @return array
     */
    private function sanitizeCourseData(array $courseData): array
    {
        // Handle JSON fields that might be arrays or objects
        $jsonFields = ['meta', 'ios_data', 'additional_authors', 'suppl_cids'];
        
        foreach ($jsonFields as $field) {
            if (isset($courseData[$field])) {
                if (is_array($courseData[$field]) || is_object($courseData[$field])) {
                    $courseData[$field] = json_encode($courseData[$field]);
                } elseif (is_string($courseData[$field]) && !empty($courseData[$field])) {
                    // Try to decode and re-encode to ensure valid JSON
                    $decoded = json_decode($courseData[$field], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $courseData[$field] = json_encode($decoded);
                    }
                } else {
                    $courseData[$field] = null;
                }
            }
        }
        
        // Handle text fields that might contain arrays - convert to strings
        $textFields = [
            'long_description', 'short_description', 'page_html', 'sandbox_html',
            'title', 'page_title', 'meta_title', 'meta_description', 'changelog'
        ];
        
        foreach ($textFields as $field) {
            if (isset($courseData[$field])) {
                if (is_array($courseData[$field])) {
                    $courseData[$field] = implode(', ', $courseData[$field]);
                } elseif (!is_string($courseData[$field]) && !is_null($courseData[$field])) {
                    $courseData[$field] = (string) $courseData[$field];
                }
            }
        }
        
        // Handle URL fields that might be arrays
        $urlFields = [
            'author_url', 'bigpageurl', 'fb_comments_url', 'fb_like_url', 'fb_share_url',
            'perma_link', 'youtube_intro_link'
        ];
        
        foreach ($urlFields as $field) {
            if (isset($courseData[$field])) {
                if (is_array($courseData[$field])) {
                    $courseData[$field] = !empty($courseData[$field]) ? $courseData[$field][0] : null;
                } elseif (!is_string($courseData[$field]) && !is_null($courseData[$field])) {
                    $courseData[$field] = (string) $courseData[$field];
                }
            }
        }
        
        // Handle boolean fields - convert to proper boolean values
        $booleanFields = [
            'allow_streaming', 'allow_firesale', 'is_free', 'mp4_ready',
            'is_compilation', 'is_foundry', 'is_hd', 'is_camp',
            'is_playstore', 'jp_course', 'featured', 'aligned_with_artist'
        ];
        
        foreach ($booleanFields as $field) {
            if (isset($courseData[$field])) {
                $courseData[$field] = (bool) $courseData[$field];
            }
        }
        
        // Handle date fields - ensure they're properly formatted
        $dateFields = ['version_date', 'new_till', 'release_date', 'early_access_date', 'document_date'];
        
        foreach ($dateFields as $field) {
            if (isset($courseData[$field])) {
                if ($courseData[$field] === '0000-00-00' || $courseData[$field] === '' || is_array($courseData[$field])) {
                    $courseData[$field] = null;
                }
            }
        }
        
        // Handle timestamp fields
        if (isset($courseData['last_updated'])) {
            if ($courseData['last_updated'] === '0000-00-00 00:00:00' || $courseData['last_updated'] === '' || is_array($courseData['last_updated'])) {
                $courseData['last_updated'] = null;
            }
        }
        
        // Handle numeric fields
        $numericFields = ['course_size', 'course_size_hd', 'authorid', 'free_remaining', 'video_count'];
        
        foreach ($numericFields as $field) {
            if (isset($courseData[$field])) {
                if ($courseData[$field] === '' || is_array($courseData[$field])) {
                    $courseData[$field] = null;
                } elseif (!is_numeric($courseData[$field])) {
                    $courseData[$field] = null;
                }
            }
        }
        
        // Handle decimal fields
        if (isset($courseData['artist_per_view_royalty'])) {
            if ($courseData['artist_per_view_royalty'] === '' || is_array($courseData['artist_per_view_royalty'])) {
                $courseData['artist_per_view_royalty'] = null;
            } elseif (!is_numeric($courseData['artist_per_view_royalty'])) {
                $courseData['artist_per_view_royalty'] = null;
            }
        }
        
        // Handle string fields that might be arrays or objects
        $stringFields = ['path', 'checksum', 'document_checksum', 'segments_checksum', 'soundslice_checksum', 'class', 'status', 'studio', 'persona', 'staff_pic', 'version', 'workshop_study_guide', 'moov'];
        
        foreach ($stringFields as $field) {
            if (isset($courseData[$field])) {
                if (is_array($courseData[$field])) {
                    $courseData[$field] = !empty($courseData[$field]) ? (string) $courseData[$field][0] : null;
                } elseif (is_object($courseData[$field])) {
                    $courseData[$field] = json_encode($courseData[$field]);
                } elseif (!is_string($courseData[$field]) && !is_null($courseData[$field])) {
                    $courseData[$field] = (string) $courseData[$field];
                }
            }
        }
        
        return $courseData;
    }
}
