<?php

namespace App\Queue;

use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Jobs\DatabaseJobRecord;

class PriorityDatabaseQueue extends DatabaseQueue
{
    /**
     * Push a new job onto the queue.
     */
    public function push($job, $data = '', $queue = null)
    {
        $queue = $this->getQueue($queue);
        $payload = $this->createPayload($job, $queue, $data);
        
        // Extract priority from job data if available
        $priority = 0;
        if (isset($payload['data']) && is_array($payload['data'])) {
            foreach ($payload['data'] as $item) {
                if (is_object($item) && method_exists($item, 'getJobPriority')) {
                    $priority = $item->getJobPriority();
                    break;
                }
            }
        }

        return $this->database->table($this->table)->insertGetId([
            'queue' => $queue,
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $this->availableAt(),
            'created_at' => $this->currentTime(),
            'priority' => $priority,
        ]);
    }

    /**
     * Pop the next job off of the queue with prioritization.
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        $job = $this->database->table($this->table)
            ->where('queue', $queue)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $this->currentTime())
            ->orderBy('priority', 'desc') // Higher priority first
            ->orderBy('id', 'asc') // FIFO for same priority
            ->first();

        if (!is_null($job)) {
            // Reserve the job
            $this->database->table($this->table)
                ->where('id', $job->id)
                ->update([
                    'reserved_at' => $this->currentTime(),
                    'attempts' => $job->attempts + 1,
                ]);

            return new DatabaseJob(
                $this->container,
                $this,
                new DatabaseJobRecord((object) $job),
                $this->connectionName,
                $queue
            );
        }

        return null;
    }
} 