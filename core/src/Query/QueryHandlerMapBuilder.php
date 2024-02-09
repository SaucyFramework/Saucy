<?php

namespace Saucy\Core\Query;

use Robertbaelde\AttributeFinder\AttributeFinder;
use Robertbaelde\AttributeFinder\ClassAttribute;
use Robertbaelde\AttributeFinder\MethodAttribute;
use Saucy\Tasks\ClassMethod;

final readonly class QueryHandlerMapBuilder
{
    /**
     * @param array<class-string> $classes
     */
    public static function buildQueryMapForClasses(array $classes): QueryMap
    {
        $attributes = AttributeFinder::inClasses($classes)->withName(QueryHandler::class)->findAll();
        $map = [];
        foreach ($attributes as $attribute){
            if($attribute instanceof ClassAttribute){
                throw new \Exception('Class ' . $attribute->class . ' is annotated with ' . QueryHandler::class . ' but class query handlers are not supported yet');
            }
            if($attribute instanceof MethodAttribute){
                $parameters = $attribute->method->getParameters();
                if(count($parameters) === 0){
                    throw new \Exception('Method ' . $attribute->method->getDeclaringClass() . '::' . $attribute->method->getName() . ' is annotated with ' . QueryHandler::class . ' but has no parameters');
                }
                $map[$parameters[0]->getType()->getName()] = new ClassMethod($attribute->method->getDeclaringClass()->getName(), $attribute->method->getName());
            }
        }

        return new QueryMap($map);
    }
}
