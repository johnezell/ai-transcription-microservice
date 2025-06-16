<?php

namespace Database\Seeders;

use App\Models\LocalTruefireAuthor;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class LocalTruefireAuthorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Path to the authors JSON file
        $jsonPath = base_path('authors.json');
        
        if (!File::exists($jsonPath)) {
            $this->command->error("Authors JSON file not found at: {$jsonPath}");
            return;
        }
        
        $this->command->info('Loading authors from JSON file...');
        
        try {
            // Read and decode the JSON file
            $jsonContent = File::get($jsonPath);
            
            // Clean up potential encoding issues
            $jsonContent = mb_convert_encoding($jsonContent, 'UTF-8', 'UTF-8');
            $jsonContent = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $jsonContent); // Remove control characters
            
            $authorsData = json_decode($jsonContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->command->error('Error parsing JSON: ' . json_last_error_msg());
                
                // Try to find the problematic part
                $this->command->error('JSON content preview (first 500 chars): ' . substr($jsonContent, 0, 500));
                return;
            }
            
            if (!is_array($authorsData)) {
                $this->command->error('Invalid JSON structure: expected array of authors');
                return;
            }
            
            $this->command->info('Found ' . count($authorsData) . ' authors in JSON file');
            
            // Clear existing authors
            $this->command->info('Clearing existing authors...');
            LocalTruefireAuthor::truncate();
            
            // Prepare authors data for bulk insert
            $authorsToInsert = [];
            $skippedCount = 0;
            $processedCount = 0;
            
            foreach ($authorsData as $authorData) {
                // Validate required fields
                if (!isset($authorData['AuthorID']) || !is_numeric($authorData['AuthorID'])) {
                    $skippedCount++;
                    continue;
                }
                
                $authorsToInsert[] = [
                    'authorid' => (int) $authorData['AuthorID'],
                    'authorfirstname' => $this->cleanString($authorData['AuthorFirstName'] ?? null),
                    'authorlastname' => $this->cleanString($authorData['AuthorLastName'] ?? null),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                
                $processedCount++;
                
                // Insert in batches of 1000 to avoid memory issues
                if (count($authorsToInsert) >= 1000) {
                    DB::table('local_truefire_authors')->insert($authorsToInsert);
                    $authorsToInsert = [];
                    $this->command->info("Inserted batch, processed {$processedCount} authors so far...");
                }
            }
            
            // Insert remaining authors
            if (count($authorsToInsert) > 0) {
                DB::table('local_truefire_authors')->insert($authorsToInsert);
            }
            
            $this->command->info("✅ Successfully imported {$processedCount} authors");
            
            if ($skippedCount > 0) {
                $this->command->warn("⚠️ Skipped {$skippedCount} authors due to missing/invalid AuthorID");
            }
            
            // Show some statistics
            $totalAuthors = LocalTruefireAuthor::count();
            $authorsWithNames = LocalTruefireAuthor::whereNotNull('authorfirstname')
                ->orWhereNotNull('authorlastname')
                ->count();
            
            $this->command->info("📊 Final Statistics:");
            $this->command->info("   Total authors: {$totalAuthors}");
            $this->command->info("   Authors with names: {$authorsWithNames}");
            
        } catch (\Exception $e) {
            $this->command->error('Error importing authors: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Clean and validate string data.
     *
     * @param mixed $value
     * @return string|null
     */
    private function cleanString($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        if (is_array($value)) {
            return null;
        }
        
        $cleaned = trim((string) $value);
        return $cleaned !== '' ? $cleaned : null;
    }
}
