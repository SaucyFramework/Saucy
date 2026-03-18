<?php

namespace Saucy\Core\Projections;

use EventSauce\EventSourcing\AggregateRootId;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Projections\Replay\SupportsBackgroundReplay;
use Saucy\Core\Subscriptions\Consumers\TypeBasedConsumer;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumerThatResetsStreamBeforeReplay;

abstract class IlluminateDatabaseProjector extends TypeBasedConsumer implements SupportsBackgroundReplay, MessageConsumerThatResetsStreamBeforeReplay
{
    protected Builder $queryBuilder;
    private string $scopedAggregateRootId;
    private bool $replayMode = false;

    public function __construct(private Connection $connection)
    {
        $this->queryBuilder = $this->connection->table($this->activeTableName());
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function upsert(array $data): void
    {
        if ($this->queryBuilder->exists()) {
            $this->update($data);
            return;
        }
        $this->create($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function update(array $data): void
    {
        $this->queryBuilder->clone()->update($data);
    }
    protected function increment(string $column, int $amount = 1): void
    {
        $this->queryBuilder->clone()->increment($column, $amount);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function create(array $data): void
    {
        $this->queryBuilder->clone()->insert(array_merge($data, [
            $this->idColumnName() => $this->scopedAggregateRootId,
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function find(): ?array
    {
        $row = $this->queryBuilder->clone()->first();
        if ($row === null) {
            return null;
        }
        return get_object_vars($row);
    }

    protected function delete(): void
    {
        $this->queryBuilder->clone()->delete();
    }

    protected function tableName(): string
    {
        return 'projection_' . Str::of(get_class($this))->afterLast('\\')->snake();
    }

    protected function activeTableName(): string
    {
        return $this->replayMode ? $this->replayTableNameFor($this->tableName()) : $this->tableName();
    }

    /**
     * Helper for multi-table projectors: returns the replay variant of any table name.
     */
    protected function replayTableNameFor(string $tableName): string
    {
        return $tableName . '_replay';
    }

    protected function isReplayMode(): bool
    {
        return $this->replayMode;
    }

    /**
     * Helper for multi-table projectors: atomically swaps a single table pair.
     */
    protected function swapTable(string $liveTable): void
    {
        $replay = $this->replayTableNameFor($liveTable);
        $old = $liveTable . '_old';
        $driver = $this->connection->getDriverName();

        match ($driver) {
            'mysql' => $this->connection->unprepared(
                "RENAME TABLE `{$liveTable}` TO `{$old}`, `{$replay}` TO `{$liveTable}`",
            ),
            default => $this->connection->transaction(function () use ($liveTable, $replay, $old) {
                $schema = $this->connection->getSchemaBuilder();
                $schema->rename($liveTable, $old);
                $schema->rename($replay, $liveTable);
            }),
        };
    }

    abstract protected function schema(Blueprint $blueprint): void;

    protected function idColumnName(): string
    {
        return 'id';
    }

    protected function scopeAggregate(string|AggregateRootId $aggregateRootId): void
    {
        if ($aggregateRootId instanceof AggregateRootId) {
            $aggregateRootId = $aggregateRootId->toString();
        }
        $this->scopedAggregateRootId = $aggregateRootId;
        $this->queryBuilder = $this->queryBuilder->where($this->idColumnName(), $aggregateRootId);
    }

    public function handle(MessageConsumeContext $context): void
    {
        $this->queryBuilder = $this->connection->table($this->activeTableName());
        if (!$context->streamName instanceof AggregateStreamName) {
            throw new Exception('Can only use this projector with aggregate root streams');
        }
        $this->scopeAggregate($context->streamName->aggregateRootIdAsString());

        $this->migrate();

        parent::handle($context);
    }

    protected function migrate(): void
    {
        $table = $this->activeTableName();
        try {
            $this->connection->getSchemaBuilder()->hasTable($table) || $this->connection->getSchemaBuilder()->create($table, function (Blueprint $blueprint) {
                $this->schema($blueprint);
            });
        } catch (\PDOException $e) {
            // race condition, table already exists
            if ($e->getCode() === '42S01') {
                return;
            }
            throw $e;
        }

    }

    protected function reset(): void
    {
        $this->connection->getSchemaBuilder()->dropIfExists($this->activeTableName());
    }

    public function setupReplayTables(): void
    {
        $replayTable = $this->tableName() . '_replay';
        $schemaBuilder = $this->connection->getSchemaBuilder();
        $schemaBuilder->dropIfExists($replayTable);
        $schemaBuilder->create($replayTable, function (Blueprint $blueprint) {
            $this->schema($blueprint);
        });
    }

    public function activateReplayMode(): void
    {
        $this->replayMode = true;
    }

    public function deactivateReplayMode(): void
    {
        $this->replayMode = false;
    }

    public function swapReplayTables(): void
    {
        $this->swapTable($this->tableName());
    }

    public function cleanupAfterSwap(): void
    {
        $this->connection->getSchemaBuilder()->dropIfExists($this->tableName() . '_old');
    }

    public function teardownReplayTables(): void
    {
        $this->connection->getSchemaBuilder()->dropIfExists($this->tableName() . '_replay');
    }

    public function prepareStreamReplay(AggregateStreamName $streamName): void
    {
        $this->migrate();
        $this->connection->table($this->tableName())
            ->where($this->idColumnName(), $streamName->aggregateRootIdAsString())
            ->delete();
    }
}
