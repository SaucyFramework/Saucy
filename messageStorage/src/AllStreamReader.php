<?php

namespace Saucy\MessageStorage;

use Generator;

interface AllStreamReader
{
    /**
     * @return Generator<int, StoredEvent>
     */
    public function paginate(AllStreamQuery $streamQuery): Generator;

    public function maxEventId(): int;
}
