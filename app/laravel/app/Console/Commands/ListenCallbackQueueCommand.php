<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCallbackQueueJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ListenCallbackQueueCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:listen-callbacks {--daemon : Run in daemon mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Listen for callbacks on the SQS callback queue';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting to listen for callbacks on the SQS queue...');
        
        $daemon = $this->option('daemon');
        
        if ($daemon) {
            $this->info('Running in daemon mode - will continuously process the queue');
            
            while (true) {
                $this->processQueue();
                sleep(10); // Wait 10 seconds between processing batches
            }
        } else {
            $this->info('Running in one-time mode - will process the queue once');
            $this->processQueue();
        }
        
        return 0;
    }
    
    /**
     * Process the queue once
     *
     * @return void
     */
    protected function processQueue()
    {
        try {
            $this->info('Dispatching job to process callback queue...');
            ProcessCallbackQueueJob::dispatch();
        } catch (\Exception $e) {
            $this->error('Error dispatching queue processing job: ' . $e->getMessage());
            Log::error('Error in ListenCallbackQueueCommand', [
                'error' => $e->getMessage()
            ]);
        }
    }
} 