<?php

namespace App\Queue;

use Illuminate\Queue\Connectors\DatabaseConnector;

/**
 * Підставляє чергу, стійку до локів SQLite, замість штатної.
 */
class RetryingDatabaseConnector extends DatabaseConnector
{
    /**
     * {@inheritDoc}
     */
    public function connect(array $config)
    {
        return new RetryingDatabaseQueue(
            $this->connections->connection($config['connection'] ?? null),
            $config['table'],
            $config['queue'],
            $config['retry_after'] ?? 60,
            $config['after_commit'] ?? null,
        );
    }
}
