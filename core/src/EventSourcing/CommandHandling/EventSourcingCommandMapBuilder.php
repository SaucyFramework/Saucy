<?php

namespace Saucy\Core\EventSourcing\CommandHandling;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use Saucy\Core\Command\CommandHandler;
use Saucy\Core\Command\CommandTask;
use Saucy\Core\Command\CommandTaskMap;
use Saucy\Core\EventSourcing\Aggregate;
use Saucy\Tasks\ClassMethod;
use Saucy\Tasks\TaskBuilder;

final readonly class EventSourcingCommandMapBuilder
{
    /**
     * @param array<class-string> $classes
     * @return CommandTaskMap
     */
    public static function buildTaskMapForClasses(array $classes): CommandTaskMap
    {
        $map = [];
        foreach($classes as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method){
                $attributes = $method->getAttributes(CommandHandler::class);

                if(count($attributes) === 0) {
                    continue;
                }

                /** @var CommandHandler $attribute */
                $attribute = $attributes[0]->newInstance();

                $handlingCommandReflectionType = $method->getParameters()[0]->getType();
                if(!$handlingCommandReflectionType instanceof ReflectionNamedType){
                    if($handlingCommandReflectionType instanceof ReflectionUnionType){
                        throw new \Exception("Union command handlers not yet supported");
                    }
                    if($handlingCommandReflectionType instanceof \ReflectionIntersectionType){
                        throw new \Exception("Intersection type as command arguments are not supported");
                    }
                    throw new \Exception("Method '$class@{$method->getName()}' marked with CommandHandler attribute doesnt have a command as first argument");
                }

                $handlingCommand = $handlingCommandReflectionType->getName();
                if(!class_exists($handlingCommand)){
                    throw new \Exception("Method '$class@{$method->getName()}' marked with CommandHandler attribute doesnt have a command as first argument");
                }

                if(array_key_exists($handlingCommand, $map)){
                    throw new \Exception('Command ' . $handlingCommand . ' is already handled by ' . $map[$handlingCommand]->containerIdentifier . '::' . $map[$handlingCommand]->methodName); // @phpstan-ignore-line
                }

                $commandReflection = new ReflectionClass($handlingCommand);

                $aggregateRoot = $reflection->getAttributes(Aggregate::class);

                if(count($aggregateRoot) === 0) {
                    $map[$handlingCommand] = new CommandTask($handlingCommand, TaskBuilder::fromReflectionMethod($method));
                    continue;
                }

                // handler is defined as aggregate root, configure aggregate root command handler
                /** @var Aggregate $aggregateRoot */
                $aggregateRoot = $aggregateRoot[0]->newInstance();
                if($aggregateRoot->aggregateIdClass !== null){
                    foreach ($commandReflection->getProperties() as $property){
                        $propertyType = $property->getType();
                        if(!$propertyType instanceof ReflectionNamedType){
                            continue;
                        }
                        if($propertyType->getName() === $aggregateRoot->aggregateIdClass){
                            $map[$handlingCommand] = new CommandTask(
                                $handlingCommand,
                                new ClassMethod(EventSourcingCommandHandler::class, $method->isStatic() ? 'handleStatic' : 'handle'),
                                [
                                    EventSourcingCommandHandler::AGGREGATE_ROOT_CLASS => $class,
                                    EventSourcingCommandHandler::AGGREGATE_ROOT_ID_PROPERTY => $property->getName(),
                                    EventSourcingCommandHandler::AGGREGATE_METHOD => $method->getName(),
                                ]
                            );
                            continue 2;
                        }
                    }
                }

                // check if command has method that returns aggregate root id
//                foreach ($commandReflection->getMethods() as $commandMethod){
//                    if($commandMethod->getReturnType() === null){
//                        continue;
//                    }
//
//                    if($commandMethod->getReturnType()->getName() === $aggregateRoot->aggregateRootIdClass){
//                        $map[$handlingCommand] = new Handler(containerIdentifier: $class, methodName: $method->getName(), isStatic: $method->isStatic(), aggregateRootIdCommandMethod: $commandMethod->getName(), queue: $attribute->queue);
//                        continue 2;
//                    }
//                }
            }
        }
        return new CommandTaskMap(...$map);
    }
}
