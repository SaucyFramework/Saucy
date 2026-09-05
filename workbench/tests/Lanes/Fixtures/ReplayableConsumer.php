<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes\Fixtures;

use Saucy\Core\Projections\Replay\SupportsBackgroundReplay;

final class ReplayableConsumer extends RecordingConsumer implements SupportsBackgroundReplay
{
    /** @var array<int, string> */
    public array $calls = [];

    public function setupReplayTables(): void
    {
        $this->calls[] = 'setup';
    }

    public function activateReplayMode(): void
    {
        $this->calls[] = 'activate';
    }

    public function deactivateReplayMode(): void
    {
        $this->calls[] = 'deactivate';
    }

    public function swapReplayTables(): void
    {
        $this->calls[] = 'swap';
    }

    public function cleanupAfterSwap(): void
    {
        $this->calls[] = 'cleanup';
    }

    public function teardownReplayTables(): void
    {
        $this->calls[] = 'teardown';
    }
}
