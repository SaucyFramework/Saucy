<?php

namespace Saucy\Core\Subscriptions\Consumers;

use Illuminate\Database\Connection;
use Saucy\Core\Projections\ListsMessagesItHandles;
use Saucy\Core\Subscriptions\Handlers\InflectHandlerMethodsFromType;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;

abstract class TypeBasedConsumer implements MessageConsumer, ListsMessagesItHandles
{
    public function handle(MessageConsumeContext $context): void
    {
        $methods = (new InflectHandlerMethodsFromType())->handleMethods($this, $context);
        foreach ($methods as $method) {
            if (method_exists($this, $method)) {
                $this->{$method}($context->event, $context);
            }
        }
    }

    /**
     * @return array<class-string>
     */
    public static function getMessages(): array
    {
        $messages = [];
        foreach((new InflectHandlerMethodsFromType())->findMethodsToHandleEvent(static::class) as $messageClass =>  $methods){
            $messages[] = $messageClass;
        }
        return array_filter($messages, fn($type) => $type != Connection::class && $type !== MessageConsumeContext::class);
    }
}
