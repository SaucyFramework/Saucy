<?php

namespace Saucy\Core\Serialisation;

use EventSauce\EventSourcing\UnableToInflectClassName;
use EventSauce\EventSourcing\UnableToInflectEventType;


/**
 * Copied from EventSauce\EventSourcing\ClassNameInflector
 */
final readonly class TypeMap
{
    /** @var array<string, class-string>|null */
    private array|null $typeToClassMap;

    /**
     * @param array<class-string, string|non-empty-array<string>> $classToTypeMap
     */
    public function __construct(
        private array $classToTypeMap,
    )
    {
    }

    public static function of(TypeMap ...$typeMap): self
    {
        // merge
        $classToTypeMap = [];
        foreach ($typeMap as $map) {
            $classToTypeMap = array_merge($classToTypeMap, $map->classToTypeMap);
        }
        return new self($classToTypeMap);
    }

    public function classNameToType(string $className): string
    {
        $type = $this->classToTypeMap[$className] ?? null;
        is_array($type) && $type = $type[0] ?? null;

        if ( ! is_string($type)) {
            throw UnableToInflectClassName::mappingIsNotDefined($className);
        }

        return $type;
    }

    /**
     * @param string $eventType
     * @return class-string
     */
    public function typeToClassName(string $eventType): string
    {
        $this->typeToClassMap ??= $this->createConsumerMap();
        $className = $this->typeToClassMap[$eventType] ?? null;

        if ($className === null) {
            throw UnableToInflectEventType::mappingIsNotDefined($eventType);
        }

        return $className;
    }

    public function instanceToType(object $instance): string
    {
        return $this->classNameToType(get_class($instance));
    }

    /**
     * On the first consumption, create a optimized reversed lookup map.
     *
     * @return array<string, class-string>
     */
    private function createConsumerMap(): array
    {
        $map = [];

        foreach ($this->classToTypeMap as $className => $eventType) {
            if (is_string($eventType)) {
                $map[$eventType] = $className;
            } else {
                foreach ($eventType as $e) {
                    $map[$e] = $className;
                }
            }
        }

        return $map;
    }
}
