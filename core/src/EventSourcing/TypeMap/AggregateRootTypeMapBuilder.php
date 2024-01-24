<?php

namespace Saucy\Core\EventSourcing\TypeMap;

use ReflectionClass;
use Robertbaelde\AttributeFinder\AttributeFinder;
use Saucy\Core\EventSourcing\Aggregate;
use Saucy\Core\Serialisation\TypeMap;

final readonly class AggregateRootTypeMapBuilder
{
    public function __construct(private bool $allowsMissingAggregateName = false)
    {
    }

    public static function make(): self
    {
        return new self();
    }

    public function create(array $classes): TypeMap
    {
        $classMap = [];
        foreach(AttributeFinder::inClasses($classes)->withName(Aggregate::class)->findClassAttributes() as $classAttribute) {
            $attribute = $classAttribute->attribute;
            // should not be required, but makes PhpStan happy
            if (!$attribute instanceof Aggregate) {
                continue;
            }
            $classMap[$classAttribute->class] = $attribute->name ?? ($this->allowsMissingAggregateName ? $this->getNameFromClass($classAttribute->class) : throw new \Exception('Aggregate name is required for ' . $classAttribute->class ));
        }
        return new TypeMap($classMap);
    }

    /**
     * @param class-string $class
     * @return string
     */
    private function getNameFromClass(string $class): string
    {
        $reflect = new ReflectionClass($class);
        return $reflect->getShortName();
    }
}
