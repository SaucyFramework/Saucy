<?php

namespace Saucy\Core\Subscriptions\Handlers;

use EventSauce\EventSourcing\Message;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;

final class InflectHandlerMethodsFromType implements HandleMethodInflector
{
    /**
     * @var string[][]
     */
    private array $methodsByEventClass;

    public function handleMethods(object $consumer, MessageConsumeContext $context): array
    {
        $event = $context->event;
        $this->methodsByEventClass ??= $this->findMethodsToHandleEvent($consumer);

        return $this->methodsByEventClass[$event::class] ?? [];
    }

    /**
     * @return array<string, string[]>
     */
    public function findMethodsToHandleEvent(object|string $handler): array
    {
        $handlerClass = new ReflectionClass($handler);
        $methods = $handlerClass->getMethods(ReflectionMethod::IS_PUBLIC);
        $handlers = [];

        foreach ($methods as $method) {
            if ( ! $type = $this->firstParameterType($method)) {
                continue;
            }

            $acceptedTypes = $this->acceptedTypes($type);

            foreach ($acceptedTypes as $type) {
                $handlers[$type->getName()][] = $method->getName();
            }
        }

        return $handlers;
    }

    protected function firstParameterType(ReflectionMethod $method): ?ReflectionType
    {
        $parameter = $method->getParameters()[0] ?? null;

        return $parameter?->getType();
    }

    /**
     * @return ReflectionNamedType[]
     */
    protected function acceptedTypes(ReflectionType $type): array
    {
        $acceptedTypes = [];

        if ($type instanceof ReflectionNamedType) {
            $acceptedTypes[] = $type;
        } elseif ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $type) {
                if ($type instanceof ReflectionNamedType) {
                    $acceptedTypes[] = $type;
                }
            }
        }

        return $acceptedTypes;
    }
}
