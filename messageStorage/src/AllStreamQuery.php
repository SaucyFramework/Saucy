<?php

namespace Saucy\MessageStorage;

final readonly class AllStreamQuery
{
    /**
     * @param int $fromPosition
     * @param int $limit
     * @param array<string>|null $eventTypes
     * @param int $visibilityDelaySeconds Only return events whose `created_at`
     *        is older than this many seconds. Defaults to 0 for backwards
     *        compatibility. Set > 0 to avoid the auto-increment commit-order
     *        gap (see `IlluminateMessageStorage::paginate` for details).
     */
    public function __construct(
        public int $fromPosition,
        public int $limit,
        public ?array $eventTypes = [],
        public int $visibilityDelaySeconds = 0,
    ) {}
}
