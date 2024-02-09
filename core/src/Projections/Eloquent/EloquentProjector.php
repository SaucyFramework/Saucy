<?php

namespace Saucy\Core\Projections\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Subscriptions\Consumers\TypeBasedConsumer;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;

abstract class EloquentProjector extends TypeBasedConsumer
{
    protected $idValue;

    /**
     * @var class-string<Model>
     */
    protected static string $model;

    protected function find(): ?Model
    {
        return static::$model::find($this->idValue);
    }
    protected function upsert(array $array): void
    {
        if(static::$model::find($this->idValue)){
            $this->update($array);
            return;
        }
        $this->create($array);
    }

    protected function create(array $array): void
    {
        static::$model::create(array_merge([
            $this->getKeyName() => $this->idValue,
        ], $array));
    }

    protected function update(array $data): void
    {
        $model = static::$model::findOrFail($this->idValue);
        $model->writable(array_keys($data))->update($data);
    }

    protected function increment(string $column, int $increment = 1): void
    {
        $model = static::$model::findOrFail($this->idValue);
        $model->writable([$column])->increment($column, $increment);
    }

    protected function getKeyName(): string
    {
        $instance = new static::$model;
        return $instance->getKeyName();
    }

    public function handle(MessageConsumeContext $context): void
    {
        if(!$context->streamName instanceof AggregateStreamName){
            throw new \Exception('Can only use this projector with aggregate root streams');
        }
        $this->idValue = $context->streamName->aggregateRootIdAsString();
        parent::handle($context);
    }
}
