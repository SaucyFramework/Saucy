<?php

namespace Saucy\Core\Subscriptions\MessageConsumption;

final readonly class HandlerFilter implements FilterThatProcessesInBatches
{
    public function __construct(
        private MessageConsumer $messageConsumer
    ) {}

    public function handle(MessageConsumeContext $context, callable $next): void
    {
        $this->messageConsumer->handle($context);
    }

    public function handles(string $className): bool
    {
        return $this->messageConsumer instanceof $className;
    }

    public function handlesBatches(): bool
    {
        return method_exists($this->messageConsumer, 'handleBatch') && $this->messageConsumer->handleBatch();
    }

    public function beforeHandlingBatch(): void
    {
        if (method_exists($this->messageConsumer, 'beforeHandlingBatch')) {
            $this->messageConsumer->beforeHandlingBatch();
        }
    }

    public function afterHandlingBatch(): void
    {
        if (method_exists($this->messageConsumer, 'afterHandlingBatch')) {
            $this->messageConsumer->afterHandlingBatch();
        }
    }

    public function prepareReplay(): void
    {
        if (method_exists($this->messageConsumer, 'prepareReplay')) {
            $this->messageConsumer->prepareReplay();
        }
    }
}
