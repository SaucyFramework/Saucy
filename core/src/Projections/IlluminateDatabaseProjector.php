<?php

namespace Saucy\Core\Projections;

use EventSauce\EventSourcing\AggregateRootId;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Subscriptions\Consumers\TypeBasedConsumer;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;

abstract class IlluminateDatabaseProjector extends TypeBasedConsumer
{
    protected Builder $queryBuilder;
    private string $scopedAggregateRootId;

    public function __construct(private Connection $connection)
    {
        $this->queryBuilder = $this->connection->table($this->tableName());
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function upsert(array $data): void
    {
        if($this->queryBuilder->exists()) {
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
        if($row === null) {
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

    abstract protected function schema(Blueprint $blueprint): void;

    protected function idColumnName(): string
    {
        return 'id';
    }

    protected function scopeAggregate(string|AggregateRootId $aggregateRootId): void
    {
        if($aggregateRootId instanceof AggregateRootId) {
            $aggregateRootId = $aggregateRootId->toString();
        }
        $this->scopedAggregateRootId = $aggregateRootId;
        $this->queryBuilder = $this->queryBuilder->where($this->idColumnName(), $aggregateRootId);
    }

    public function handle(MessageConsumeContext $context): void
    {
        $this->queryBuilder = $this->connection->table($this->tableName());
        if(!$context->streamName instanceof AggregateStreamName) {
            throw new Exception('Can only use this projector with aggregate root streams');
        }
        $this->scopeAggregate($context->streamName->aggregateRootIdAsString());

        $this->migrate();

        parent::handle($context);
    }

    protected function migrate(): void
    {
        try {
            $this->connection->getSchemaBuilder()->hasTable($this->tableName()) || $this->connection->getSchemaBuilder()->create($this->tableName(), function (Blueprint $blueprint) {
                $this->schema($blueprint);
            });
        } catch (\PDOException $e) {
            // race condition, table already exists
            if($e->getCode() === '42S01') {
                return;
            }
            throw $e;
        }

    }

    protected function reset(): void
    {
        $this->connection->getSchemaBuilder()->dropIfExists($this->tableName());
    }
}
