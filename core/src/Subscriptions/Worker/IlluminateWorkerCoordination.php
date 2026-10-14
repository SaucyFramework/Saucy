<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

use Illuminate\Database\ConnectionInterface;

/**
 * WorkerCoordination in SQL: one key/value table (`saucy_worker_state`). Publish its migration with
 * `php artisan vendor:publish --tag=saucy-worker-migrations`.
 *
 * Rows: `member:<worker id>`, `gate`, `runner:<subscription id>`. Every write touches one row, so
 * concurrent workers never overwrite each other.
 */
final readonly class IlluminateWorkerCoordination implements WorkerCoordination
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $tableName = 'saucy_worker_state',
    ) {}

    public function heartbeat(WorkerInfo $worker): void
    {
        $this->put('member:' . $worker->id, ['sha' => $worker->sha], $worker->expiresAt);
    }

    public function liveWorkers(int $now): array
    {
        $this->connection->table($this->tableName)
            ->where('key', 'like', 'member:%')
            ->where('expires_at', '<', $now - 300)
            ->delete();

        $workers = [];
        foreach ($this->connection->table($this->tableName)->where('key', 'like', 'member:%')->where('expires_at', '>', $now)->get() as $row) {
            /** @var object{key: string, value: string, expires_at: int} $row */
            /** @var array{sha?: string|null} $value */
            $value = json_decode($row->value, true) ?: [];
            $workers[] = new WorkerInfo(substr($row->key, strlen('member:')), $value['sha'] ?? null, (int) $row->expires_at);
        }

        return $workers;
    }

    public function leave(string $workerId): void
    {
        $this->connection->table($this->tableName)->where('key', 'member:' . $workerId)->delete();
    }

    public function gate(): ?DeployGate
    {
        $row = $this->connection->table($this->tableName)->where('key', 'gate')->first();
        if ($row === null) {
            return null;
        }
        /** @var object{value: string} $row */
        /** @var array{sha: string, paused: bool, paused_at?: int|null, paused_at_position?: int|null} $value */
        $value = json_decode($row->value, true);

        return new DeployGate($value['sha'], $value['paused'], $value['paused_at_position'] ?? null, $value['paused_at'] ?? null);
    }

    public function pauseForDeploy(string $sha, ?int $head = null): void
    {
        $this->put('gate', ['sha' => $sha, 'paused' => true, 'paused_at' => time(), 'paused_at_position' => $head]);
    }

    public function liftPause(string $sha): bool
    {
        $gate = $this->gate();
        if ($gate === null || !$gate->admits($sha) || !$gate->paused) {
            return false;
        }

        // Compare-and-set on the stored value, so only one worker lifts it.
        $current = $this->connection->table($this->tableName)->where('key', 'gate')->value('value');
        if (!is_string($current)) {
            return false;
        }
        $lifted = json_encode(['sha' => $sha, 'paused' => false, 'paused_at' => $gate->pausedAtTime, 'paused_at_position' => $gate->pausedAt], JSON_THROW_ON_ERROR);

        return $this->connection->table($this->tableName)
            ->where('key', 'gate')
            ->where('value', $current)
            ->update(['value' => $lifted]) > 0;
    }

    public function clearGate(): void
    {
        $this->connection->table($this->tableName)->where('key', 'gate')->delete();
    }

    public function runnerOverrides(): array
    {
        $overrides = [];
        foreach ($this->connection->table($this->tableName)->where('key', 'like', 'runner:%')->get() as $row) {
            /** @var object{key: string, value: string} $row */
            $overrides[substr($row->key, strlen('runner:'))] = $row->value;
        }

        return $overrides;
    }

    public function setRunnerOverride(string $subscriptionId, ?string $runner): void
    {
        if ($runner === null) {
            $this->connection->table($this->tableName)->where('key', 'runner:' . $subscriptionId)->delete();

            return;
        }
        $this->connection->table($this->tableName)->updateOrInsert(['key' => 'runner:' . $subscriptionId], ['value' => $runner, 'expires_at' => null]);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function put(string $key, array $value, ?int $expiresAt = null): void
    {
        $this->connection->table($this->tableName)->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'expires_at' => $expiresAt],
        );
    }
}
