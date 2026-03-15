<?php

declare(strict_types=1);

namespace Saucy\Core\Projections\Replay;

use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;

interface SupportsBackgroundReplay extends MessageConsumer
{
    /**
     * Create empty replay tables (same schema as live tables).
     */
    public function setupReplayTables(): void;

    /**
     * Switch this projector instance to write to replay tables.
     */
    public function activateReplayMode(): void;

    /**
     * Switch this projector instance back to writing to live tables.
     */
    public function deactivateReplayMode(): void;

    /**
     * Atomically swap replay tables into the live position.
     * (live -> _old, replay -> live)
     */
    public function swapReplayTables(): void;

    /**
     * Drop the _old tables after a successful swap.
     */
    public function cleanupAfterSwap(): void;

    /**
     * Drop replay tables without swapping (cancel/abort a replay).
     */
    public function teardownReplayTables(): void;
}
