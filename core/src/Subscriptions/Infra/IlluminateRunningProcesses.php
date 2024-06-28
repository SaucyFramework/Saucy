<?php

namespace Saucy\Core\Subscriptions\Infra;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;

final readonly class IlluminateRunningProcesses implements RunningProcesses
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $tableName = 'running_processes',
        private string $pausedTableName = 'paused_subscriptions',
    ) {}


    /**
     * @throws StartProcessException
     */
    public function start(string $subscriptionId, string $processId, \DateTime $expiresAt, bool $ignorePaused = false): void
    {
        // cleanup process when running longer than x seconds
        $this->connection->table($this->tableName)
            ->where('subscription_id', $subscriptionId)
            ->where('expires_at', '<', (new \DateTime('now'))->sub(new \DateInterval('PT30S'))->format('Y-m-d H:i:s'))
            ->delete();

        if(!$ignorePaused) {
            $paused = $this->connection->table($this->pausedTableName)
                ->where('subscription_id', $subscriptionId)
                ->first();

            if($paused !== null) {
                throw StartProcessException::subscriptionIsPaused($paused->reason);
            }
        }

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
        if($this->isPaused($subscriptionId)) {
            return false;
        }

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

        if ($this->isPaused($row->subscription_id)) {
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

    /**
     * @return RunningProcess[]
     */
    public function all(): array
    {
        // get all paused rows
        $paused = $this->connection->table($this->pausedTableName)->get();
        $allSubscriptions = $this->connection->table($this->tableName)->get();

        // map and set paused status
        return $allSubscriptions->mapWithKeys(function ($row) use ($paused) {
            return [$row->subscription_id =>  new RunningProcess(
                subscriptionId: $row->subscription_id,
                processId: $row->process_id,
                expiresAt: new \DateTime($row->expires_at),
                paused: $paused->contains('subscription_id', $row->subscription_id),
                pausedReason: $paused->firstWhere('subscription_id', $row->subscription_id)?->reason,
                status: $row->status,
                lastStatusAt: $row->last_status_change_at ? new \DateTime($row->last_status_change_at) : null,
            )];
        })->merge($paused->mapWithKeys(function ($row) {
            return [$row->subscription_id =>  new RunningProcess(
                subscriptionId: $row->subscription_id,
                processId: 'paused',
                expiresAt: new \DateTime('now'),
                paused: true,
                pausedReason: $row->reason,
                status: 'paused',
                lastStatusAt: null,
            )];
        }))->toArray();
    }

    public function pause(string $subscriptionId, ?string $reason = null): void
    {
        $this->connection->table($this->pausedTableName)
            ->insert([
                'subscription_id' => $subscriptionId,
                'reason' => $reason,
            ]);
    }

    public function resume(string $subscriptionId): void
    {
        $this->connection->table($this->pausedTableName)
            ->where('subscription_id', $subscriptionId)
            ->delete();
    }

    public function reportStatus(string $processId, string $status): void
    {
        $this->connection->table($this->tableName)
            ->where('process_id', $processId)
            ->update([
                'status' => $status,
                'last_status_change_at' => (new \DateTime('now'))->format('Y-m-d H:i:s'),
            ]);
    }

    public function isPaused(string $subscriptionId): bool
    {
        return $this->connection->table($this->pausedTableName)
            ->where('subscription_id', $subscriptionId)
            ->exists();
    }
}
