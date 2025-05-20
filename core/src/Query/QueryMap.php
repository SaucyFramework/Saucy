<?php

namespace Saucy\Core\Query;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Saucy\Tasks\ClassMethod;
use Saucy\Tasks\InvokableClass;
use Saucy\Tasks\StaticClassMethod;
use Saucy\Tasks\TaskLocation;

final readonly class QueryMap implements SerializablePayload
{
    /**
     * @param array<class-string, TaskLocation> $map
     */
    public function __construct(
        public array $map,
    ) {}

    /**
     * @throws QueryHandlerNotFound
     */
    public function getForQuery(object $message): TaskLocation
    {
        return $this->map[get_class($message)] ?? throw QueryHandlerNotFound::for(get_class($message));
    }

    public function toPayload(): array
    {

        return [
            'map' => array_map(
                static fn(TaskLocation $taskLocation) => [
                    'taskLocation' => match ($taskLocation::class) {
                        ClassMethod::class => $taskLocation->toPayload(),
                        InvokableClass::class => $taskLocation->toPayload(),
                        StaticClassMethod::class => $taskLocation->toPayload(),
                    },
                    'taskLocationClass' => $taskLocation::class,
                ],
                $this->map,
            ),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            map: array_map(
                static fn(array $taskLocation) => $taskLocation['taskLocationClass']::fromPayload($taskLocation['taskLocation']),
                $payload['map'],
            ),
        );
    }
}
