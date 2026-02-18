<?php

namespace Saucy\Core\Projections;

use Robertbaelde\AttributeFinder\AttributeFinder;
use Robertbaelde\AttributeFinder\ClassAttribute;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;

final readonly class ProjectorMapBuilder
{
    /**
     * @param array<class-string> $classes
     */
    public static function buildForClasses(array $classes, TypeMap $typeMap): ProjectorMap
    {
        $attributes = AttributeFinder::inClasses($classes)->withNames(
            Projector::class,
            AggregateProjector::class,
        )->findAll();

        $projectors = [];
        foreach ($attributes as $attribute) {
            if (!$attribute instanceof ClassAttribute) {
                throw new \Exception('Class ' . $attribute->class . ' is annotated with ' . Projector::class . ' but is not annotating a class');
            }

            $projectionAttribute = $attribute->attribute;
            /** @var class-string<MessageConsumer> $projectorClass */
            $projectorClass = $attribute->class;

            $projectors[] = match (get_class($projectionAttribute)) {
                Projector::class => new ProjectorConfig(
                    projectorClass: $projectorClass,
                    handlingEventClasses: $projectorClass::getMessages(),
                    projectorType: ProjectorType::AllStream,
                    pageSize: $projectionAttribute->pageSize,
                    commitBatchSize: $projectionAttribute->commitBatchSize,
                    failureMode: $projectionAttribute->failureMode,
                ),
                AggregateProjector::class => new ProjectorConfig(
                    projectorClass: $projectorClass,
                    handlingEventClasses: $projectorClass::getMessages(),
                    projectorType: ProjectorType::AggregateInstance,
                    aggregateType: $typeMap->classNameToType($projectionAttribute->aggregateClass),
                    async: $projectionAttribute->async,
                    failureMode: $projectionAttribute->failureMode,
                ),
                default => throw new \Exception("projection attribute not supported"),
            };
        }

        return new ProjectorMap(...$projectors);
    }
}
