<?php

namespace Saucy\Core\Projections;

interface ListsMessagesItHandles
{
    /**
     * @return array<class-string>
     */
    public static function getMessages(): array;
}
