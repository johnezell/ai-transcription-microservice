<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\LocalTruefireChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class LocalTruefireChannelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder loads channels data from channels.json file
     * to populate the local_truefire_channels table.
     */
    public function run(): void
    {
        $this->command->info('Starting TrueFire channels synchronization from JSON...');
        
        try {
            // Disable foreign key constraints temporarily
            $this->command->info('Disabling foreign key constraints...');
            DB::statement('PRAGMA foreign_keys = OFF');
            
            // Check if channels.json file exists
            $jsonFilePath = base_path('channels.json');
            if (!file_exists($jsonFilePath)) {
                $this->command->error('channels.json file not found in project root');
                return;
            }
            
            // Read and decode JSON file
            $this->command->info('Reading channels.json file...');
            $jsonContent = file_get_contents($jsonFilePath);
            $channelsData = json_decode($jsonContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->command->error('Error parsing channels.json: ' . json_last_error_msg());
                return;
            }
            
            $totalChannels = count($channelsData);
            $this->command->info("Found {$totalChannels} channels to import");
            
            // Clear existing channel data
            $this->command->info('Clearing existing channel data...');
            LocalTruefireChannel::truncate();
            
            // Process channels in chunks to avoid memory issues
            $chunkSize = 100;
            $processedCount = 0;
            $errorCount = 0;
            
            // Process data in chunks
            $chunks = array_chunk($channelsData, $chunkSize);
            
            foreach ($chunks as $chunkIndex => $chunk) {
                $localChannels = [];
                
                foreach ($chunk as $channel) {
                    try {
                        // Sanitize channel data for local database insertion
                        $channelData = $this->sanitizeChannelData($channel);
                        
                        // Add timestamps
                        $channelData['created_at'] = now();
                        $channelData['updated_at'] = now();
                        
                        $localChannels[] = $channelData;
                        $processedCount++;
                        
                    } catch (\Exception $e) {
                        $errorCount++;
                        Log::error('Error processing channel during JSON import', [
                            'channel_id' => $channel['id'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                        $this->command->error("Error processing channel ID {$channel['id']}: " . $e->getMessage());
                    }
                }
                
                // Bulk insert the chunk
                if (!empty($localChannels)) {
                    try {
                        LocalTruefireChannel::insert($localChannels);
                        $this->command->info("Processed chunk " . ($chunkIndex + 1) . "/" . count($chunks) . " - {$processedCount} channels total...");
                    } catch (\Exception $e) {
                        $errorCount += count($localChannels);
                        Log::error('Error during channels bulk insert', [
                            'chunk_size' => count($localChannels),
                            'error' => $e->getMessage()
                        ]);
                        $this->command->error("Error during bulk insert: " . $e->getMessage());
                    }
                }
            }
            
            $this->command->info("Channels synchronization completed!");
            $this->command->info("Successfully processed: {$processedCount} channels");
            
            if ($errorCount > 0) {
                $this->command->warn("Channel errors encountered: {$errorCount}");
            }
            
            // Verify the sync
            $localChannelsCount = LocalTruefireChannel::count();
            $this->command->info("Local channels table now contains: {$localChannelsCount} records");
            
        } catch (\Exception $e) {
            Log::error('Critical error during channels JSON import', [
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
     * Sanitize channel data for local database insertion.
     *
     * @param array $channelData
     * @return array
     */
    private function sanitizeChannelData(array $channelData): array
    {
        // Handle boolean fields
        $booleanFields = ['new_item', 'on_sale', 'top_picks'];
        foreach ($booleanFields as $field) {
            if (isset($channelData[$field])) {
                if (is_string($channelData[$field])) {
                    $channelData[$field] = in_array(strtolower($channelData[$field]), ['true', '1', 'yes', 'on']);
                } else {
                    $channelData[$field] = (bool) $channelData[$field];
                }
            }
        }
        
        // Handle thumbnails field (enum: 'true' or 'false' as strings)
        if (isset($channelData['thumbnails'])) {
            $channelData['thumbnails'] = (string) $channelData['thumbnails'];
            if (!in_array($channelData['thumbnails'], ['true', 'false'])) {
                $channelData['thumbnails'] = 'false';
            }
        }
        
        // Handle date fields
        if (isset($channelData['date_modified'])) {
            if ($channelData['date_modified'] === '0000-00-00 00:00:00' || 
                $channelData['date_modified'] === '' || 
                is_null($channelData['date_modified'])) {
                $channelData['date_modified'] = null;
            }
        }
        
        // Handle time fields
        if (isset($channelData['run_time'])) {
            if ($channelData['run_time'] === '00:00:00' || 
                $channelData['run_time'] === '' || 
                is_null($channelData['run_time'])) {
                $channelData['run_time'] = '00:00:00';
            }
        }
        
        // Handle numeric fields
        $numericFields = ['level1', 'tf_itemid', 'tf_authorid', 'courseid'];
        foreach ($numericFields as $field) {
            if (isset($channelData[$field])) {
                if ($channelData[$field] === '' || is_null($channelData[$field])) {
                    $channelData[$field] = null;
                } elseif (!is_numeric($channelData[$field])) {
                    $channelData[$field] = null;
                } else {
                    $channelData[$field] = (int) $channelData[$field];
                }
            }
        }
        
        // Handle array fields that should be stored as comma-separated strings
        $arrayFields = ['style', 'curriculum', 'level'];
        foreach ($arrayFields as $field) {
            if (isset($channelData[$field])) {
                if (is_array($channelData[$field])) {
                    $channelData[$field] = implode(',', $channelData[$field]);
                } elseif (!is_string($channelData[$field])) {
                    $channelData[$field] = (string) $channelData[$field];
                }
            }
        }
        
        // Handle string fields - ensure they're strings and handle nulls
        $stringFields = [
            'xml_filename', 'title', 'posterframe', 'adimage', 'adlink', 
            'guide', 'emailredirect', 'prerollchance', 'prerollgroup', 
            'postroll', 'bannerform', 'menuImage', 'video', 'commercial', 
            'more', 'adimage2', 'adlink2', 'foldername', 'version', 
            'inlinechance', 'inlinegroup', 'bandwidthHi', 'bandwidthMed', 
            'tf_thumb', 'tf_thumb2', 'educator_name', 'educator_url', 'video_prefix'
        ];
        
        foreach ($stringFields as $field) {
            if (isset($channelData[$field])) {
                if (is_null($channelData[$field])) {
                    $channelData[$field] = '';
                } else {
                    $channelData[$field] = (string) $channelData[$field];
                }
            } else {
                $channelData[$field] = '';
            }
        }
        
        // Handle text fields
        $textFields = ['description', 'add_fields', 'ch_extra_assets'];
        foreach ($textFields as $field) {
            if (isset($channelData[$field])) {
                if (is_null($channelData[$field])) {
                    $channelData[$field] = '';
                } else {
                    $channelData[$field] = (string) $channelData[$field];
                }
            } else {
                $channelData[$field] = '';
            }
        }
        
        // Ensure required fields have default values if missing
        $defaults = [
            'xml_filename' => '',
            'title' => '',
            'posterframe' => '',
            'adimage' => '',
            'adlink' => '',
            'thumbnails' => 'false',
            'guide' => '',
            'emailredirect' => '',
            'prerollchance' => '',
            'prerollgroup' => '',
            'postroll' => '',
            'bannerform' => '',
            'description' => '',
            'menuImage' => '',
            'video' => '',
            'commercial' => '',
            'more' => '',
            'adimage2' => '',
            'adlink2' => '',
            'foldername' => '',
            'version' => '',
            'inlinechance' => '',
            'inlinegroup' => '',
            'bandwidthHi' => '',
            'bandwidthMed' => '',
            'run_time' => '00:00:00',
            'new_item' => false,
            'on_sale' => false,
            'top_picks' => false,
            'tf_thumb' => '',
            'tf_thumb2' => '',
            'educator_name' => '',
            'educator_url' => '',
            'video_prefix' => '',
            'add_fields' => '',
            'ch_extra_assets' => ''
        ];
        
        foreach ($defaults as $field => $defaultValue) {
            if (!isset($channelData[$field])) {
                $channelData[$field] = $defaultValue;
            }
        }
        
        return $channelData;
    }
} 