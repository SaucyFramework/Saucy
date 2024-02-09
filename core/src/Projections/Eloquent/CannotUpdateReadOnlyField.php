<?php

namespace Saucy\Core\Projections\Eloquent;

final class CannotUpdateReadOnlyField extends \Exception
{
    public static function forFields(array $fields)
    {
        if (count($fields) === 1) {
            return new self("Field {$fields[0]} is read only");
        }

        $fieldsString = implode(', ', $fields);
        return new self("Fields {$fieldsString} are read only");
    }
}
