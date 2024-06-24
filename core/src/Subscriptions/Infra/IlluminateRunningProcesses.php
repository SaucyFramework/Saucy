<?php

namespace Saucy\Core\Subscriptions\Infra;

use Illuminate\Database\ConnectionInterface;
use PDOException;

final readonly class IlluminateRunningProcesses implements RunningProcesses
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $tableName = 'running_processes',
    ) {}


    /**
     * @throws StartProcessException
     */
    public function start(string $subscriptionId, string $processId, \DateTime $expiresAt): void
    {
        // cleanup process when running longer than x seconds
        $this->connection->table($this->tableName)
            ->where('subscription_id', $subscriptionId)
            ->where('expires_at', '<', (new \DateTime('now'))->sub(new \DateInterval('PT30S'))->format('Y-m-d H:i:s'))
            ->delete();

        try {
            $this->connection->table($this->tableName)->insert([
                'subscription_id' => $subscriptionId,
                'process_id' => $processId,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            throw StartProcessException::cannotGetLockForProcess();
        }

    }

    public function isActive(string $subscriptionId, ?string $processId = null): bool
    {
        return $this->connection->table($this->tableName)
            ->where('subscription_id', $subscriptionId)
            ->when($processId !== null, fn($query) => $query->where('process_id', $processId))
            ->where('expires_at', '>', (new \DateTime('now'))->format('Y-m-d H:i:s'))
            ->exists();
    }

    public function timeLeft(string $processId): int
    {
        $row = $this->connection->table($this->tableName)
            ->where('process_id', $processId)
            ->first();

        if($row === null) {
            return 0;
        }
        return (new \DateTime($row->expires_at))->getTimestamp() - time();
    }

    public function stop(string $processId): void
    {
        $this->connection->table($this->tableName)
            ->where('process_id', $processId)
            ->delete();
    }
}
