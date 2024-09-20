<?php

namespace Saucy\Core\Subscriptions\Consumers;

use Illuminate\Support\Facades\Concurrency;
use Saucy\Core\Subscriptions\Handlers\InflectHandlerMethodsFromType;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumerThatHandlesBatches;

abstract class ConcurrentTypeBasedConsumer extends TypeBasedConsumer implements MessageConsumerThatHandlesBatches
{
    private array $concurrent;

    public function getBatchSize(): int
    {
        return 1000;
    }

    public function getConcurrency(): int
    {
        return 10;
    }

    public function beforeHandlingBatch(): void
    {
        $this->concurrent = [];
    }

    protected function getPartition(string $key): int
    {
        // Convert the hexadecimal hash to an integer
        $hash_int = abs(hexdec(hash('murmur3a', $key)));
        // Compute the partition number
        return $hash_int % $this->getConcurrency();
    }

    public function afterHandlingBatch(): void
    {
        $tasks = [];
        $handlerClass = get_class($this);

        foreach ($this->concurrent as $partition => $events) {
            if (empty($events)) {
                continue;
            }

            $tasks[] = function () use ($handlerClass, $events) {
                $handler = app($handlerClass);
                $handler->handleEvents($events);
            };
        }

        Concurrency::run($tasks);
        $this->concurrent = [];
    }

    abstract public function concurrencyKey(object $event, MessageConsumeContext $context): string;

    public function handle(MessageConsumeContext $context): void
    {
        $partition = $this->getPartition($this->concurrencyKey($context->event, $context));
        $this->concurrent[$partition][] = $context;
    }

    /**
     * @param array<MessageConsumeContext> $events
     */
    private function handleEvents(array $events): void
    {
        foreach ($events as $context) {
            $methods = (new InflectHandlerMethodsFromType())->handleMethods($this, $context);
            foreach ($methods as $method) {
                if (method_exists($this, $method)) {
                    $this->{$method}($context->event, $context);
                }
            }
        }
    }
}
