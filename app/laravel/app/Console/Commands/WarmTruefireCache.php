<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\TruefireCourseController;

class WarmTruefireCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'truefire:warm-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm up the TrueFire course cache by pre-loading commonly accessed data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Warming TrueFire course cache...');
        
        try {
            $controller = new TruefireCourseController();
            $response = $controller->warmCache();
            
            $responseData = json_decode($response->getContent(), true);
            
            if ($responseData['success']) {
                $this->info('✅ ' . $responseData['message']);
                return Command::SUCCESS;
            } else {
                $this->error('❌ ' . $responseData['message']);
                return Command::FAILURE;
            }
            
        } catch (\Exception $e) {
            $this->error('❌ Error warming cache: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
} 