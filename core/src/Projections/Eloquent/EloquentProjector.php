<?php

namespace Saucy\Core\Projections\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Subscriptions\Consumers\TypeBasedConsumer;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumerThatResetsStreamBeforeReplay;

abstract class EloquentProjector extends TypeBasedConsumer implements MessageConsumerThatResetsStreamBeforeReplay
{
    protected string $idValue;

    /**
     * @var class-string<Model>
     */
    protected static string $model;

    protected function find(): ?Model
    {
        return static::$model::find($this->idValue);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function upsert(array $data): void
    {
        if (static::$model::find($this->idValue)) {
            $this->update($data);
            return;
        }
        $this->create($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function create(array $data): void
    {
        static::$model::create(array_merge([
            $this->getKeyName() => $this->idValue,
        ], $data));
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function update(array $data): void
    {
        $model = static::$model::findOrFail($this->idValue);
        $model->writable(array_keys($data))->update($data); // @phpstan-ignore-line
    }

    protected function increment(string $column, int $increment = 1): void
    {
        $model = static::$model::findOrFail($this->idValue);
        $model->writable([$column])->increment($column, $increment); // @phpstan-ignore-line
    }

    protected function getKeyName(): string
    {
        $instance = new static::$model();
        return $instance->getKeyName();
    }

    public function handle(MessageConsumeContext $context): void
    {
        if (!$context->streamName instanceof AggregateStreamName) {
            throw new \Exception('Can only use this projector with aggregate root streams');
        }
        $this->idValue = $context->streamName->aggregateRootIdAsString();
        parent::handle($context);
    }

    public function prepareStreamReplay(AggregateStreamName $streamName): void
    {
        static::$model::where(
            (new static::$model())->getKeyName(),
            $streamName->aggregateRootIdAsString(),
        )->delete();
    }
}
