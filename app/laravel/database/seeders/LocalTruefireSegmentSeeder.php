<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\LocalTruefireSegment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class LocalTruefireSegmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder loads segments data from segments.json file
     * to populate the local_truefire_segments table.
     */
    public function run(): void
    {
        $this->command->info('Starting TrueFire segments synchronization from JSON...');
        
        try {
            // Disable foreign key constraints temporarily
            $this->command->info('Disabling foreign key constraints...');
            DB::statement('PRAGMA foreign_keys = OFF');
            
            // Check if segments.json file exists
            $jsonFilePath = base_path('segments.json');
            if (!file_exists($jsonFilePath)) {
                $this->command->error('segments.json file not found in project root');
                return;
            }
            
            // Read and decode JSON file
            $this->command->info('Reading segments.json file...');
            $jsonContent = file_get_contents($jsonFilePath);
            $segmentsData = json_decode($jsonContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->command->error('Error parsing segments.json: ' . json_last_error_msg());
                return;
            }
            
            $totalSegments = count($segmentsData);
            $this->command->info("Found {$totalSegments} segments to import");
            
            // Clear existing segment data
            $this->command->info('Clearing existing segment data...');
            LocalTruefireSegment::truncate();
            
            // Process segments in chunks to avoid memory issues
            $chunkSize = 100;
            $processedCount = 0;
            $errorCount = 0;
            
            // Process data in chunks
            $chunks = array_chunk($segmentsData, $chunkSize);
            
            foreach ($chunks as $chunkIndex => $chunk) {
                $localSegments = [];
                
                foreach ($chunk as $segment) {
                    try {
                        // Sanitize segment data for local database insertion
                        $segmentData = $this->sanitizeSegmentData($segment);
                        
                        // Add timestamps
                        $segmentData['created_at'] = now();
                        $segmentData['updated_at'] = now();
                        
                        $localSegments[] = $segmentData;
                        $processedCount++;
                        
                    } catch (\Exception $e) {
                        $errorCount++;
                        Log::error('Error processing segment during JSON import', [
                            'segment_id' => $segment['id'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                        $this->command->error("Error processing segment ID {$segment['id']}: " . $e->getMessage());
                    }
                }
                
                // Bulk insert the chunk
                if (!empty($localSegments)) {
                    try {
                        LocalTruefireSegment::insert($localSegments);
                        $this->command->info("Processed chunk " . ($chunkIndex + 1) . "/" . count($chunks) . " - {$processedCount} segments total...");
                    } catch (\Exception $e) {
                        $errorCount += count($localSegments);
                        Log::error('Error during segments bulk insert', [
                            'chunk_size' => count($localSegments),
                            'error' => $e->getMessage()
                        ]);
                        $this->command->error("Error during bulk insert: " . $e->getMessage());
                    }
                }
            }
            
            $this->command->info("Segments synchronization completed!");
            $this->command->info("Successfully processed: {$processedCount} segments");
            
            if ($errorCount > 0) {
                $this->command->warn("Segment errors encountered: {$errorCount}");
            }
            
            // Verify the sync
            $localSegmentsCount = LocalTruefireSegment::count();
            $this->command->info("Local segments table now contains: {$localSegmentsCount} records");
            
        } catch (\Exception $e) {
            Log::error('Critical error during segments JSON import', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->command->error('Critical error during synchronization: ' . $e->getMessage());
            throw $e;
        } finally {
            // Re-enable foreign key constraints
            $this->command->info('Re-enabling foreign key constraints...');
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Sanitize segment data for local database insertion.
     *
     * @param array $segmentData
     * @return array
     */
    private function sanitizeSegmentData(array $segmentData): array
    {
        // Handle boolean fields
        if (isset($segmentData['is_hd'])) {
            if (is_string($segmentData['is_hd'])) {
                $segmentData['is_hd'] = in_array(strtolower($segmentData['is_hd']), ['true', '1', 'yes', 'on']);
            } else {
                $segmentData['is_hd'] = (bool) $segmentData['is_hd'];
            }
        }
        
        // Handle date fields that can be null
        $dateFields = ['document_date'];
        foreach ($dateFields as $field) {
            if (isset($segmentData[$field])) {
                if ($segmentData[$field] === '0000-00-00 00:00:00' || 
                    $segmentData[$field] === '' || 
                    is_null($segmentData[$field])) {
                    $segmentData[$field] = null;
                }
            }
        }
        
        // Handle nullable string fields
        $nullableStringFields = ['document_checksum'];
        foreach ($nullableStringFields as $field) {
            if (isset($segmentData[$field])) {
                if ($segmentData[$field] === '' || is_null($segmentData[$field])) {
                    $segmentData[$field] = null;
                }
            }
        }
        
        // Handle numeric fields
        $numericFields = ['id', 'channel_id', 'sub_channel_id', 'item_order', 'runtime'];
        foreach ($numericFields as $field) {
            if (isset($segmentData[$field])) {
                if ($segmentData[$field] === '' || is_null($segmentData[$field])) {
                    $segmentData[$field] = 0; // Default to 0 for required numeric fields
                } elseif (!is_numeric($segmentData[$field])) {
                    $segmentData[$field] = 0;
                } else {
                    $segmentData[$field] = (int) $segmentData[$field];
                }
            }
        }
        
        // Handle string fields - ensure they're strings and handle nulls
        $stringFields = [
            'xmlchannel', 'name', 'subhead', 'video', 'preroll', 'prerollgroup', 
            'prerollchance', 'postroll', 'img1', 'imgurl1', 'img2', 'imgurl2', 
            'img3', 'imgurl3', 'adgroup', 'adgroup_count', 'more', 'thumbnail', 
            'assettab', 'asseturl', 'free', 'cd', 'tab', 'jam', 'pt', 'Level', 'Style'
        ];
        
        foreach ($stringFields as $field) {
            if (isset($segmentData[$field])) {
                if (is_null($segmentData[$field])) {
                    $segmentData[$field] = '';
                } else {
                    $segmentData[$field] = (string) $segmentData[$field];
                }
            } else {
                $segmentData[$field] = '';
            }
        }
        
        // Handle text fields
        $textFields = ['description', 'extra_assets'];
        foreach ($textFields as $field) {
            if (isset($segmentData[$field])) {
                if (is_null($segmentData[$field])) {
                    $segmentData[$field] = '';
                } else {
                    $segmentData[$field] = (string) $segmentData[$field];
                }
            } else {
                $segmentData[$field] = '';
            }
        }
        
        // Ensure required fields have default values if missing
        $defaults = [
            'xmlchannel' => '',
            'name' => '',
            'subhead' => '',
            'video' => '',
            'preroll' => '',
            'prerollgroup' => '',
            'prerollchance' => '',
            'postroll' => '',
            'img1' => '',
            'imgurl1' => '',
            'img2' => '',
            'imgurl2' => '',
            'img3' => '',
            'imgurl3' => '',
            'adgroup' => '',
            'adgroup_count' => '',
            'more' => '',
            'thumbnail' => '',
            'assettab' => '',
            'asseturl' => '',
            'free' => '',
            'description' => '',
            'cd' => '',
            'tab' => '',
            'jam' => '',
            'pt' => '',
            'extra_assets' => '',
            'Level' => '',
            'Style' => '',
            'runtime' => 0,
            'is_hd' => true
        ];
        
        foreach ($defaults as $field => $defaultValue) {
            if (!isset($segmentData[$field])) {
                $segmentData[$field] = $defaultValue;
            }
        }
        
        return $segmentData;
    }
} 