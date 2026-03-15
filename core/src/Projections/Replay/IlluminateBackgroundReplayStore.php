<?php

declare(strict_types=1);

namespace Saucy\Core\Projections\Replay;

use Illuminate\Database\ConnectionInterface;

final readonly class IlluminateBackgroundReplayStore implements BackgroundReplayStore
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $tableName = 'background_replays',
    ) {}

    public function start(string $subscriptionId): void
    {
        $this->connection->table($this->tableName)->updateOrInsert(
            ['subscription_id' => $subscriptionId],
            [
                'status' => ReplayStatus::Running->value,
                'failure_reason' => null,
                'started_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function getStatus(string $subscriptionId): ?ReplayStatus
    {
        /** @var object{status: string}|null $row */
        $row = $this->connection->table($this->tableName)
            ->where('subscription_id', $subscriptionId)
            ->first();

        if ($row === null) {
            return null;
        }

        return ReplayStatus::from($row->status);
    }

    public function markSwapping(string $subscriptionId): void
    {
        $this->connection->table($this->tableName)
            ->where('subscription_id', $subscriptionId)
            ->update([
                'status' => ReplayStatus::Swapping->value,
                'updated_at' => now(),
            ]);
    }

    public function markCompleted(string $subscriptionId): void
    {
        $this->connection->table($this->tableName)
            ->where('subscription_id', $subscriptionId)
            ->update([
                'status' => ReplayStatus::Completed->value,
                'updated_at' => now(),
            ]);
    }

    public function markFailed(string $subscriptionId, string $reason): void
    {
        $this->connection->table($this->tableName)
            ->where('subscription_id', $subscriptionId)
            ->update([
                'status' => ReplayStatus::Failed->value,
                'failure_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    public function remove(string $subscriptionId): void
    {
        $this->connection->table($this->tableName)
            ->where('subscription_id', $subscriptionId)
            ->delete();
    }

    /**
     * @return array<string, ReplayStatus>
     */
    public function getAll(): array
    {
        /** @var array<string, ReplayStatus> */
        return $this->connection->table($this->tableName)
            ->get()
            ->mapWithKeys(fn(object $row) => [
                $row->subscription_id => ReplayStatus::from($row->status),
            ])
            ->toArray();
    }
}
