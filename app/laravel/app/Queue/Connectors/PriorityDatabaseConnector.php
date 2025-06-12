<?php

namespace App\Queue\Connectors;

use Illuminate\Queue\Connectors\DatabaseConnector;
use App\Queue\PriorityDatabaseQueue;

class PriorityDatabaseConnector extends DatabaseConnector
{
    /**
     * Establish a queue connection.
     */
    public function connect(array $config)
    {
        return new PriorityDatabaseQueue(
            $this->connections->connection($config['connection'] ?? null),
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null
        );
    }
} 