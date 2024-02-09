<?php

namespace Saucy\Core\Subscriptions\Infra;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use PDOException;

final readonly class IlluminateRunningProcesses implements RunningProcesses
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $tableName = 'running_processes',
    )
    {
    }


    /**
     * @throws StartProcessException
     */
    public function start(string $subscriptionId, string $processId, \DateTime $expiresAt): void
    {
        try {
            $this->connection->table($this->tableName)->insert([
                'subscription_id' => $subscriptionId,
                'process_id' => $processId,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e){
            Log::notice("pdo exception: " . $e->getCode());
            throw StartProcessException::cannotGetLockForProcess();
        }

    }

    public function isActive(string $subscriptionId, ?string $processId = null): bool
    {
        return $this->connection->table($this->tableName)
            ->where('subscription_id', $subscriptionId)
            ->when($processId !== null, fn ($query) => $query->where('process_id', $processId))
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function stop(string $processId): void
    {
        $this->connection->table($this->tableName)
            ->where('process_id', $processId)
            ->delete();
    }
}
