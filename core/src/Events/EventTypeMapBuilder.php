<?php

namespace Saucy\Core\Events;

use Robertbaelde\AttributeFinder\AttributeFinder;
use Saucy\Core\Serialisation\TypeMap;

final readonly class EventTypeMapBuilder
{
    public static function make(): self
    {
        return new self();
    }

    public function create(array $classes): TypeMap
    {
        $classMap = [];
        foreach(AttributeFinder::inClasses($classes)->withName(Event::class)->findClassAttributes() as $classAttribute) {
            $attribute = $classAttribute->attribute;
            // should not be required, but makes PhpStan happy
            if (!$attribute instanceof Event) {
                continue;
            }
            $classMap[$classAttribute->class] = $attribute->name;
        }
        return new TypeMap($classMap);
    }
}
