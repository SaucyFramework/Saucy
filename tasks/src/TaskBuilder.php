<?php

namespace Saucy\Tasks;

use ReflectionMethod;

final readonly class TaskBuilder
{
    public static function fromReflectionMethod(ReflectionMethod $reflectionMethod): TaskLocation
    {

        if($reflectionMethod->isStatic()) {
            return new StaticClassMethod(
                className: $reflectionMethod->getDeclaringClass()->getName(),
                methodName: $reflectionMethod->getName(),
            );
        }
        return new ClassMethod(
            className: $reflectionMethod->getDeclaringClass()->getName(),
            methodName: $reflectionMethod->getName(),
        );
    }
}
