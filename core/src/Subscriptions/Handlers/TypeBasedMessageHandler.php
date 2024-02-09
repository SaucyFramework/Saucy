<?php

namespace Saucy\Core\Subscriptions\Handlers;

use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;

final readonly class TypeBasedMessageHandler implements MessageConsumer
{
    private HandleMethodInflector $handleMethodInflector;

    public function handle(MessageConsumeContext $context): void
    {
        $this->handleMethodInflector ??= $this->handleMethodInflector();
        $methods = $this->handleMethodInflector->handleMethods($this, $context);

        foreach ($methods as $method) {
            if (method_exists($this, $method)) {
                $this->{$method}($context->event, $context);
            }
        }
    }

    protected function handleMethodInflector(): HandleMethodInflector
    {
        return new InflectHandlerMethodsFromType();
    }
}
