<?php

namespace Saucy\Core\Query;

use Exception;

final class QueryHandlerNotFound extends Exception
{
    public static function for(string $get_class): self
    {
        return new self("No query handler found for query: {$get_class}");
    }
}
